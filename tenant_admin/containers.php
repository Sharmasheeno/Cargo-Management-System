<?php
// tenant_admin/containers.php
// Maareynta Kontaynarada - Cargo Management System - Tenant Admin
// Full version with Export/Import CSV functionality

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is tenant_admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant_admin') {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

// Security: If no tenant is assigned, redirect
if (!$session_tenant_id) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Tenant Admin';

// Get tenant name
$tenant_name = '';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant['name'] ?? 'Shirkaddayda';
} catch (PDOException $e) {
    $tenant_name = 'Shirkaddayda';
}

// ── Ensure warehouse columns exist before any query references them ──────────
$_col_patches = [
    "ALTER TABLE containers MODIFY COLUMN status
         ENUM('received','loading','loaded','shipped','dispatched','at_port','ready','delivered') DEFAULT 'received'",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS container_type ENUM('20ft','40ft','40hc','lcl') DEFAULT '20ft'",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS size_cbm DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS size_used_cbm DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS current_location VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS arrival_date DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS departure_date DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS estimated_arrival DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS seal_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS shipping_line VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS bl_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS vessel_name VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS port_of_loading VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS port_of_discharge VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS eta_port DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS etd_port DATE DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS customs_status ENUM('pending','cleared','held') DEFAULT 'pending'",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS created_by INT(11) DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL",
    "ALTER TABLE containers ADD COLUMN IF NOT EXISTS current_branch_id INT(11) DEFAULT NULL",
    
    "ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_status
         ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'",
    "ALTER TABLE warehouse_stock MODIFY COLUMN mogadishu_status
         ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'",
    "ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_received_date DATETIME DEFAULT NULL",
    "ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS mogadishu_taken_date    DATETIME DEFAULT NULL",
    "ALTER TABLE warehouse_stock ADD COLUMN IF NOT EXISTS storage_fee             DECIMAL(15,2) DEFAULT 0.00",

    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_status
         ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'",
    "ALTER TABLE cargo_manifest_items MODIFY COLUMN mogadishu_status
         ENUM('not_arrived','in_warehouse','taken','delivered') NOT NULL DEFAULT 'not_arrived'",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_received_date DATETIME DEFAULT NULL",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS mogadishu_taken_date    DATETIME DEFAULT NULL",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS storage_fee             DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS weight_kg               DECIMAL(15,2) DEFAULT 0.00",
    "ALTER TABLE cargo_manifest_items ADD COLUMN IF NOT EXISTS unit_price              DECIMAL(15,2) DEFAULT 0.00"
];
foreach ($_col_patches as $_cp) {
    try { $pdo->exec($_cp); } catch (PDOException $e) { /* ignore */ }
}
unset($_col_patches, $_cp);

// Get branches for this tenant
$branches = [];
try {
    $stmt = $pdo->prepare("SELECT id, branch_name, branch_type, branch_code FROM branches WHERE tenant_id = ? AND status = 'active' ORDER BY branch_name");
    $stmt->execute([$session_tenant_id]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branches = [];
}

// Container type to CBM mapping
$container_cbm_map = [
    '20ft' => 33.2,
    '40ft' => 67.6,
    '40hc' => 76.3,
    'lcl' => 0
];

// Xaalad definitions
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


// ==============================================
// CONTAINER STATUS WORKFLOW LOCK
// ==============================================
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


// ==============================================
// GREEN API WHATSAPP CONFIGURATION
// ==============================================
$greenConfig = __DIR__ . '/../config/greenapi_connect.php';
$waHelper = __DIR__ . '/../includes/whatsapp_helper.php';
if (file_exists($greenConfig)) {
    require_once $greenConfig;
}
if (file_exists($waHelper)) {
    require_once $waHelper;
}

$GREEN_API_ID = defined('GREEN_API_ID') ? GREEN_API_ID : (getenv('GREEN_API_ID') ?: '');
$GREEN_API_TOKEN = defined('GREEN_API_TOKEN') ? GREEN_API_TOKEN : (getenv('GREEN_API_TOKEN') ?: '');
$GREEN_API_URL = defined('GREEN_API_URL') ? GREEN_API_URL : (getenv('GREEN_API_URL') ?: '');

function formatSomaliTelefoonForContainer($phone) {
    $phone = preg_replace('/\D/', '', (string)$phone);
    if ($phone === '') return '';
    if (strlen($phone) === 9 && in_array($phone[0], ['6', '7'], true)) return '252' . $phone;
    if (strlen($phone) === 10 && $phone[0] === '0') return '252' . substr($phone, 1);
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '252') return $phone;
    return '252' . ltrim($phone, '0');
}

function sendWhatsAppGreenAPIForContainer($phone, $message, $idInstance, $apiToken, $apiUrl) {
    $formattedTelefoon = formatSomaliTelefoonForContainer($phone);
    if ($formattedTelefoon === '') {
        return ['success' => false, 'error' => 'Telefoon sax ah lama helin'];
    }

    if (function_exists('sendWhatsAppMessage')) {
        return sendWhatsAppMessage($formattedTelefoon, $message);
    }
    if (function_exists('sendWhatsApp')) {
        return sendWhatsApp($formattedTelefoon, $message);
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'PHP cURL extension lama shidin'];
    }

    $payload = [
        'chatId' => $formattedTelefoon . '@c.us',
        'message' => $message
    ];

    $endpoints = ['sendMessage', 'SendMessage'];
    $lastError = null;

    foreach ($endpoints as $endpoint) {
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string)$response, true);
        $lastError = [
            'success' => false,
            'error' => $curlError ?: ($decoded['message'] ?? $response ?? 'Khalad aan la garanayn'),
            'http_code' => $httpCode,
            'endpoint' => $endpoint,
            'api_response' => $decoded ?: $response
        ];

        if ($httpCode === 200 && is_array($decoded) && isset($decoded['idMessage'])) {
            return [
                'success' => true,
                'message_id' => $decoded['idMessage'],
                'endpoint' => $endpoint,
                'api_response' => $decoded
            ];
        }
    }

    return $lastError ?: ['success' => false, 'error' => 'WhatsApp lama dirin'];
}


function tableColumnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function addTableColumnIfMissing($pdo, $table, $column, $definition) {
    if (!tableColumnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function ensureWhatsAppContainerLogsSchema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_container_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) NOT NULL,
        `container_id` int(11) NOT NULL,
        `customer_id` int(11) DEFAULT NULL,
        `phone` varchar(30) NOT NULL,
        `status` varchar(50) DEFAULT NULL,
        `message` text NOT NULL,
        `send_status` varchar(20) DEFAULT 'pending',
        `api_response` text DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'tenant_id', "int(11) NOT NULL DEFAULT 0");
    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'container_id', "int(11) NOT NULL DEFAULT 0");
    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'customer_id', "int(11) DEFAULT NULL");
    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'phone', "varchar(30) NOT NULL DEFAULT ''");
    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'status', "varchar(50) DEFAULT NULL");
    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'message', "text NOT NULL");
    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'send_status', "varchar(20) DEFAULT 'pending'");
    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'api_response', "text DEFAULT NULL");
    addTableColumnIfMissing($pdo, 'whatsapp_container_logs', 'created_at', "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
}

function somaliContainerXaaladText($status) {
    $map = [
        'received' => 'Waa la helay',
        'loading' => 'Waa la rarayaa',
        'loaded' => 'Waa la raray',
        'shipped' => 'Wuu dhoofay',
        'dispatched' => 'Waa la diray',
        'at_port' => 'Wuxuu jooga dekedda',
        'ready' => 'Alaabtu waa diyaar',
        'delivered' => 'Waa la gaarsiiyay'
    ];
    return $map[$status] ?? $status;
}

function buildContainerSomaliMessage($customerName, $companyName, array $container, $itemsList = '') {
    $status = $container['status'] ?? '';
    $statusText = somaliContainerXaaladText($status);
    $currentTime = date('d/m/Y H:i');
    $customerName = trim((string)$customerName) !== '' ? trim((string)$customerName) : 'Macaamiil';

    $message  = "Macmiil: {$customerName}\n";
    $message .= "Container update\n";
    $message .= "Container: " . ($container['container_number'] ?? '-') . "\n";

    if (!empty($container['tracking_number'])) {
        $message .= "Code: " . $container['tracking_number'] . "\n";
    }
    if (!empty($container['bl_number'])) {
        $message .= "BL: " . $container['bl_number'] . "\n";
    }
    if (!empty($container['current_location'])) {
        $message .= "Goob: " . $container['current_location'] . "\n";
    }

    $message .= "Xaalad: {$statusText}\n";

    if (!empty($container['estimated_arrival']) && $container['estimated_arrival'] !== '0000-00-00') {
        $message .= "ETA: " . date('d/m/Y', strtotime($container['estimated_arrival'])) . "\n";
    }
    if (!empty($container['arrival_date']) && $container['arrival_date'] !== '0000-00-00') {
        $message .= "Arrival: " . date('d/m/Y', strtotime($container['arrival_date'])) . "\n";
    }
    if (trim((string)$itemsList) !== '') {
        $message .= "Alaab: {$itemsList}\n";
    }

    $message .= "Date: {$currentTime}\n";
    $message .= $companyName;

    return $message;
}

function sendContainerXaaladWhatsAppToMacmiils($pdo, $containerId, $tenantId, $status = null) {
    global $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL;

    try {
        $tenantStmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $tenantStmt->execute([$tenantId]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        $companyName = $tenant['name'] ?? 'Shirkadda';

        $containerStmt = $pdo->prepare("
            SELECT id, container_number, status, current_location, arrival_date, departure_date,
                   estimated_arrival, tracking_number, bl_number, vessel_name, port_of_loading,
                   port_of_discharge
            FROM containers
            WHERE id = ? AND tenant_id = ?
        ");
        $containerStmt->execute([$containerId, $tenantId]);
        $container = $containerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$container) {
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'message' => 'Kontaynerka lama helin'];
        }
        if ($status !== null) {
            $container['status'] = $status;
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
            $message = buildContainerSomaliMessage(
                $customer['customer_name'] ?: 'Macmiil',
                $companyName,
                $container,
                $customer['items_list'] ?? ''
            );

            $result = sendWhatsAppGreenAPIForContainer($customer['phone'], $message, $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL);
            $sendXaalad = !empty($result['success']) ? 'sent' : 'failed';
            if ($sendXaalad === 'sent') {
                $sent++;
            } else {
                $failed++;
                $errors[] = ($customer['customer_name'] ?? 'Macmiil') . ': ' . ($result['error'] ?? 'Khalad');
            }

            $logStmt = $pdo->prepare("
                INSERT INTO whatsapp_container_logs
                    (tenant_id, container_id, customer_id, phone, status, message, send_status, api_response, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $tenantId,
                $containerId,
                $customer['customer_id'] ?? null,
                $customer['phone'],
                $container['status'],
                $message,
                $sendXaalad,
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
    } catch (Exception $e) {
        error_log('Container WhatsApp Khalad: ' . $e->getMessage());
        return ['success' => false, 'sent' => 0, 'failed' => 0, 'message' => $e->getMessage()];
    }
}

// ==============================================
// HANDLE GET ACTIONS (EXPORT & DOWNLOAD)
// ==============================================
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // EXPORT CONTAINERS
    if ($action === 'export_containers') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=containers_export_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['ID', 'Lambarka Kontaynarka', 'Nooca', 'Cabbirka (CBM)', 'Miisaanka (KG)', 'Xaalad', 'Laanta Hadda', 'Goobta Hadda', 'Taariikhda Imaanshaha', 'Taariikhda Dhoofka', 'Lambarka Seal-ka', 'Lambarka BL', 'Magaca Markabka', 'Taariikhda Abuurista']);
        
        $where_conditions = ["c.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        $search = $_GET['search'] ?? '';
        if (!empty($search)) {
            $where_conditions[] = "(c.container_number LIKE ? OR c.tracking_number LIKE ? OR c.bl_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $sql = "SELECT c.id, c.container_number, c.container_type, c.size_cbm, c.weight_kg, c.status, b.branch_name, c.current_location, c.arrival_date, c.departure_date, c.seal_number, c.bl_number, c.vessel_name, c.created_at 
                FROM containers c 
                LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id 
                $where_clause 
                ORDER BY c.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
    
    // EXPORT MANIFEST
    elseif ($action === 'export_manifest') {
        $container_id = $_GET['id'] ?? 0;
        
        $stmt = $pdo->prepare("SELECT container_number FROM containers WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$container_id, $session_tenant_id]);
        $container = $stmt->fetch();
        
        if (!$container) die("Kontaynar lama helin");
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=manifest_'.$container['container_number'].'_'.date('Y-m-d').'.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Magaca Macmiilka', 'Telefoon', 'Wadarta Xirmooyinka', 'Wadarta CBM', 'Miisaanka (KG)', 'Qiimaha/CBM', 'Wadarta Qiimaha', 'Kharashka Kaydinta', 'Liiska Alaabta', 'Xaaladda Muqdisho']);
        
        $sql = "
            SELECT 
                COALESCE(cust.customer_name, '-') AS customer_name,
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
            ORDER BY cust.customer_name, cmi.stock_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$container_id, $session_tenant_id]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    // DOWNLOAD IMPORT TEMPLATE FOR CONTAINERS
    elseif ($action === 'download_import_template') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=containers_import_template.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, [
            'container_number','container_type','size_cbm','weight_kg','status','current_location',
            'arrival_date','departure_date','estimated_arrival','tracking_number','seal_number','notes',
            'shipping_line','bl_number','vessel_name','port_of_loading','port_of_discharge',
            'eta_port','etd_port','customs_status'
        ]);
        fputcsv($output, [
            'MSKU1234567','20ft','33.2','0','received','Yiwu Warehouse',
            '','','2026-06-20','TRK-20260525-1001','SEAL123','Sample note',
            'MSC','BL123456','MSC SOMALIA','Yiwu','Mogadishu',
            '2026-06-20','','pending'
        ]);
        fclose($output);
        exit;
    }
    
    // DOWNLOAD MANIFEST IMPORT TEMPLATE
    elseif ($action === 'download_manifest_template') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=manifest_import_template.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, [
            'customer_name', 'phone', 'stock_name', 'quantity', 'cbm_used', 'weight_kg', 'unit_price'
        ]);
        fputcsv($output, [
            'Ahmed Ali', '612345678', 'Laptop', '10', '2.5', '50', '100'
        ]);
        fputcsv($output, [
            'Fatima Hassan', '615556677', 'Rice 50kg', '100', '5.0', '2500', '25'
        ]);
        fclose($output);
        exit;
    }
}

// ==============================================
// HANDLE AJAX REQUESTS
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];

    // IMPORT CONTAINERS FROM CSV
    if ($action === 'import_containers') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro CSV file sax ah.']);
            exit;
        }

        $allowed_statuses = ['received','loading','loaded','shipped','dispatched','at_port','ready','delivered'];
        $allowed_types = ['20ft','40ft','40hc','lcl'];
        $allowed_customs = ['pending','cleared','held'];
        $container_cbm_map_import = ['20ft' => 33.2, '40ft' => 67.6, '40hc' => 76.3, 'lcl' => 0];

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

        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        try {
            $pdo->beginTransaction();
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
                    continue;
                }

                $data = [];
                foreach ($header as $i => $key) {
                    $data[$key] = trim($row[$i] ?? '');
                }

                $container_number = $data['container_number'] ?? '';
                if ($container_number === '') {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: container_number waa madhan yahay.";
                    continue;
                }

                $check = $pdo->prepare("SELECT id FROM containers WHERE container_number = ? AND tenant_id = ?");
                $check->execute([$container_number, $session_tenant_id]);
                if ($check->fetch()) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: kontaynerkan horay ayuu u jiray ({$container_number}).";
                    continue;
                }

                $container_type = $data['container_type'] ?? '20ft';
                if (!in_array($container_type, $allowed_types, true)) $container_type = '20ft';

                $status = $data['status'] ?? 'received';
                if (!in_array($status, $allowed_statuses, true)) $status = 'received';

                $customs_status = $data['customs_status'] ?? 'pending';
                if (!in_array($customs_status, $allowed_customs, true)) $customs_status = 'pending';

                $size_cbm = isset($data['size_cbm']) && $data['size_cbm'] !== '' ? (float)$data['size_cbm'] : ($container_cbm_map_import[$container_type] ?? 0);
                $weight_kg = isset($data['weight_kg']) && $data['weight_kg'] !== '' ? (float)$data['weight_kg'] : 0;
                $tracking_number = $data['tracking_number'] ?? '';
                if ($tracking_number === '') $tracking_number = 'TRK-' . date('Ymd') . '-' . rand(1000, 9999);

                $dateFields = ['arrival_date','departure_date','estimated_arrival','eta_port','etd_port'];
                foreach ($dateFields as $field) {
                    if (!empty($data[$field])) {
                        $time = strtotime($data[$field]);
                        $data[$field] = $time ? date('Y-m-d', $time) : null;
                    } else {
                        $data[$field] = null;
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO containers (
                    tenant_id, container_number, container_type, size_cbm, weight_kg, status,
                    current_location, arrival_date, departure_date, estimated_arrival, tracking_number,
                    seal_number, notes, shipping_line, bl_number, vessel_name, port_of_loading,
                    port_of_discharge, eta_port, etd_port, customs_status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $session_tenant_id,
                    $container_number,
                    $container_type,
                    $size_cbm,
                    $weight_kg,
                    $status,
                    $data['current_location'] ?? '',
                    $data['arrival_date'],
                    $data['departure_date'],
                    $data['estimated_arrival'],
                    $tracking_number,
                    $data['seal_number'] ?? '',
                    $data['notes'] ?? '',
                    $data['shipping_line'] ?? '',
                    $data['bl_number'] ?? '',
                    $data['vessel_name'] ?? '',
                    $data['port_of_loading'] ?? '',
                    $data['port_of_discharge'] ?? '',
                    $data['eta_port'],
                    $data['etd_port'],
                    $customs_status,
                    $_SESSION['user_id']
                ]);

                $container_id = $pdo->lastInsertId();
                $trip_number = 'TRP-' . date('ymd') . '-' . str_pad($container_id, 3, '0', STR_PAD_LEFT);
                $tripStmt = $pdo->prepare("INSERT INTO trucking_trips (tenant_id, container_id, trip_number, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $tripStmt->execute([$session_tenant_id, $container_id, $trip_number]);
                $inserted++;
            }
            fclose($handle);
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Soo geli waa dhammaaday: {$inserted} waa la geliyay, {$skipped} waa la dhaafay.",
                'inserted' => $inserted,
                'skipped' => $skipped,
                'errors' => array_slice($errors, 0, 10)
            ]);
        } catch (Exception $e) {
            if (is_resource($handle)) fclose($handle);
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Soo geli khalad ayuu galay: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // IMPORT MANIFEST TO CONTAINER FROM CSV
    elseif ($action === 'import_manifest_to_container') {
        $container_id = (int)($_POST['container_id'] ?? 0);
        
        if ($container_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Container sax ah lama helin']);
            exit;
        }
        
        // Check if container belongs to this tenant and is not locked
        $checkStmt = $pdo->prepare("SELECT status, container_number FROM containers WHERE id = ? AND tenant_id = ?");
        $checkStmt->execute([$container_id, $session_tenant_id]);
        $container = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$container) {
            echo json_encode(['success' => false, 'message' => 'Kontaynar lama helin ama fasax uma lihid']);
            exit;
        }
        
        if (isContainerManifestLocked($container['status'])) {
            echo json_encode(['success' => false, 'message' => "Kontaynarkan '{$container['container_number']}' wuu dhoofay; alaab cusub laguma dari karo"]);
            exit;
        }
        
        if (!isset($_FILES['manifest_file']) || $_FILES['manifest_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro CSV file sax ah']);
            exit;
        }
        
        $handle = fopen($_FILES['manifest_file']['tmp_name'], 'r');
        if (!$handle) {
            echo json_encode(['success' => false, 'message' => 'File-ka lama furi karin']);
            exit;
        }
        
        // Read header
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            echo json_encode(['success' => false, 'message' => 'CSV file-ku waa madhan yahay']);
            exit;
        }
        
        $header = array_map('strtolower', array_map('trim', $header));
        
        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;
        
        try {
            $pdo->beginTransaction();
            
            // Get current total CBM used
            $currentCbmStmt = $pdo->prepare("SELECT COALESCE(SUM(cbm_used), 0) as current_cbm FROM cargo_manifest_items WHERE container_id = ? AND tenant_id = ?");
            $currentCbmStmt->execute([$container_id, $session_tenant_id]);
            $currentCbm = (float)$currentCbmStmt->fetch(PDO::FETCH_ASSOC)['current_cbm'];
            
            $containerCbmStmt = $pdo->prepare("SELECT size_cbm FROM containers WHERE id = ? AND tenant_id = ?");
            $containerCbmStmt->execute([$container_id, $session_tenant_id]);
            $containerCbm = (float)$containerCbmStmt->fetch(PDO::FETCH_ASSOC)['size_cbm'];
            
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                
                // Skip empty rows
                if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
                    continue;
                }
                
                // Map row data to columns
                $data = [];
                foreach ($header as $idx => $colName) {
                    $data[$colName] = trim($row[$idx] ?? '');
                }
                
                // Validate required fields
                $customer_name = $data['customer_name'] ?? '';
                $phone = $data['phone'] ?? '';
                $stock_name = $data['stock_name'] ?? '';
                $quantity = (int)($data['quantity'] ?? 0);
                $cbm_used = (float)($data['cbm_used'] ?? 0);
                $weight_kg = (float)($data['weight_kg'] ?? 0);
                $unit_price = (float)($data['unit_price'] ?? 0);
                
                if (empty($customer_name)) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: Magaca macmiilka waa madhan";
                    continue;
                }
                
                if (empty($stock_name)) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: Magaca alaabta waa madhan";
                    continue;
                }
                
                if ($quantity <= 0) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: Tirada waa inay ka weyn tahay 0";
                    continue;
                }
                
                if ($cbm_used <= 0) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: CBM waa inay ka weyn tahay 0";
                    continue;
                }
                
                // Check if we have enough space
                $newTotalCbm = $currentCbm + $cbm_used;
                if ($newTotalCbm > $containerCbm) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: CBM kugu filan ma jiro kontaynerka. Hadda: {$currentCbm}, Cusub: {$cbm_used}, Maximum: {$containerCbm}";
                    continue;
                }
                
                // Find or create customer
                $customerStmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ? AND tenant_id = ?");
                $customerStmt->execute([$customer_name, $session_tenant_id]);
                $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$customer) {
                    $insertCustomer = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, phone, created_at) VALUES (?, ?, ?, NOW())");
                    $insertCustomer->execute([$session_tenant_id, $customer_name, $phone]);
                    $customer_id = $pdo->lastInsertId();
                } else {
                    $customer_id = $customer['id'];
                    if (!empty($phone)) {
                        $updatePhone = $pdo->prepare("UPDATE customers SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = '')");
                        $updatePhone->execute([$phone, $customer_id]);
                    }
                }
                
                // Check if warehouse stock already exists
                $stockStmt = $pdo->prepare("
                    SELECT id, quantity, cbm_per_item, weight_per_item, unit_price, container_id 
                    FROM warehouse_stock 
                    WHERE customer_id = ? AND stock_name = ? AND tenant_id = ?
                    AND (status = 'in_warehouse' OR status IS NULL)
                    ORDER BY id DESC LIMIT 1
                ");
                $stockStmt->execute([$customer_id, $stock_name, $session_tenant_id]);
                $existingStock = $stockStmt->fetch(PDO::FETCH_ASSOC);
                
                $cbm_per_item = $cbm_used / $quantity;
                $weight_per_item = $weight_kg / $quantity;
                
                if ($existingStock && is_null($existingStock['container_id'])) {
                    $newQuantity = $existingStock['quantity'] + $quantity;
                    $newCbmUsed = $newQuantity * ($existingStock['cbm_per_item'] ?? $cbm_per_item);
                    
                    $updateStock = $pdo->prepare("
                        UPDATE warehouse_stock 
                        SET quantity = ?, 
                            cbm_used = ?,
                            weight_kg = ?,
                            unit_price = ?,
                            updated_at = NOW()
                        WHERE id = ? AND tenant_id = ?
                    ");
                    $updateStock->execute([$newQuantity, $newCbmUsed, $weight_kg, $unit_price, $existingStock['id'], $session_tenant_id]);
                    $stock_id = $existingStock['id'];
                } else {
                    $insertStock = $pdo->prepare("
                        INSERT INTO warehouse_stock (
                            tenant_id, customer_id, stock_name, quantity, cbm_per_item, cbm_used,
                            weight_per_item, weight_kg, unit_price, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_warehouse', NOW())
                    ");
                    $insertStock->execute([
                        $session_tenant_id, $customer_id, $stock_name, $quantity,
                        $cbm_per_item, $cbm_used, $weight_per_item, $weight_kg, $unit_price
                    ]);
                    $stock_id = $pdo->lastInsertId();
                }
                
                // Add to cargo manifest
                $insertManifest = $pdo->prepare("
                    INSERT INTO cargo_manifest_items (
                        tenant_id, container_id, warehouse_stock_id, stock_name,
                        quantity, cbm_used, weight_kg, unit_price, added_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insertManifest->execute([
                    $session_tenant_id, $container_id, $stock_id, $stock_name,
                    $quantity, $cbm_used, $weight_kg, $unit_price
                ]);
                
                // Update container size_used_cbm
                $currentCbm += $cbm_used;
                $updateContainerCbm = $pdo->prepare("UPDATE containers SET size_used_cbm = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                $updateContainerCbm->execute([$currentCbm, $container_id, $session_tenant_id]);
                
                $inserted++;
            }
            
            fclose($handle);
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Soo geli waa dhammaaday: {$inserted} alaab waa la daray, {$skipped} waa la dhaafay.",
                'inserted' => $inserted,
                'skipped' => $skipped,
                'errors' => array_slice($errors, 0, 10)
            ]);
        } catch (Exception $e) {
            if (is_resource($handle)) fclose($handle);
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Soo geli khalad ayuu galay: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET CONTAINERS (AJAX PAGINATION)
    elseif ($action === 'get_containers') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $branch_filter = isset($_POST['branch']) ? (int)$_POST['branch'] : 0;
        $status_filter = $_POST['status'] ?? '';
        
        $where_conditions = ["c.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where_conditions[] = "(c.container_number LIKE ? OR c.tracking_number LIKE ? OR c.bl_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($branch_filter > 0) {
            $where_conditions[] = "c.current_branch_id = ?";
            $params[] = $branch_filter;
        }
        
        if (!empty($status_filter)) {
            $where_conditions[] = "c.status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM containers c $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_containers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_containers / $limit);
        
        $sql = "
            SELECT c.*, 
                   b.branch_name as branch_name
            FROM containers c
            LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
            $where_clause
            ORDER BY c.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate table HTML (same as before)
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="containers-table" style="min-width: 1200px; width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f6f9;">
                        <th style="padding: 12px;">ID</th>
                        <th style="padding: 12px;">Lambarka Kontaynarka</th>
                        <th style="padding: 12px;">Nooca</th>
                        <th style="padding: 12px;">CBM</th>
                        <th style="padding: 12px;">Xaalad</th>
                        <th style="padding: 12px;">Laanta</th>
                        <th style="padding: 12px;">Safarkii Ugu Dambeeyay</th>
                        <th style="padding: 12px;">Lambarka BL</th>
                        <th style="padding: 12px;">Hawlo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($containers) > 0): ?>
                        <?php foreach ($containers as $container): 
                            $statusColor = $status_colors[$container['status']] ?? '#6c757d';
                            $statusName = $status_names[$container['status']] ?? ucfirst($container['status']);
                            $containerNooca = $container['container_type'] ?? '20ft';
                            $tripStmt = $pdo->prepare("SELECT trip_number, status FROM trucking_trips WHERE container_id = ? AND tenant_id = ? ORDER BY created_at DESC LIMIT 1");
                            $tripStmt->execute([$container['id'], $session_tenant_id]);
                            $lastTrip = $tripStmt->fetch(PDO::FETCH_ASSOC);
                            $trackingNumber = $container['tracking_number'] ?? '';
                            $isManifestLocked = isContainerManifestLocked($container['status']);
                            $isFinalLocked = isContainerFinalLocked($container['status']);
                        ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;"><?= $container['id'] ?></td>
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($container['container_number']) ?></strong>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        <i class="fas fa-calendar-alt"></i> La abuuray: <?= date('d/m/Y', strtotime($container['created_at'])) ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;"><?= $containerNooca ?></td>
                                <td style="padding: 12px;"><?= number_format((float)($container['size_cbm'] ?? 0), 2) ?> CBM</span></td>
                                <td style="padding: 12px;">
                                    <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px;">
                                        <?= $statusName ?>
                                    </span>
                                    <?php if ($container['status'] === 'ready'): ?>
                                        <div style="font-size:10px; color:#28a745; font-weight:600; margin-top:3px;">
                                            <i class="fas fa-check-circle"></i> Waxaa loo diray Bakhaarka
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($container['customs_status'] === 'cleared'): ?>
                                        <div style="font-size:9px; color:#17a2b8;">🛃 Kastamku wuu fasaxay</div>
                                    <?php endif; ?>
                                </span>
                                <td style="padding: 12px;">
                                    <?php if (!empty($container['branch_name'])): ?>
                                        <span style="font-size: 12px;">
                                            <i class="fas fa-store"></i> <?= htmlspecialchars($container['branch_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </span>
                                <td style="padding: 12px;">
                                    <?php if ($lastTrip && $lastTrip['trip_number']): ?>
                                        <a href="shipments.php?search=<?= urlencode($lastTrip['trip_number']) ?>" class="shipment-link" style="color: #1565c0; text-decoration: none;">
                                            <?= htmlspecialchars($lastTrip['trip_number']) ?>
                                        </a>
                                        <div style="font-size: 10px;"><?= $status_names[$lastTrip['status']] ?? $lastTrip['status'] ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">Lama xirin</span>
                                    <?php endif; ?>
                                </span>
                                <td style="padding: 12px;">
                                    <?php if (!empty($container['bl_number'])): ?>
                                        <code style="font-size: 11px;"><?= htmlspecialchars($container['bl_number']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </span>
                                <td style="padding: 12px;">
                                    <div class="action-buttons" style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <button class="action-btn btn-view view-container" data-id="<?= $container['id'] ?>" title="Faahfaahin"><i class="fas fa-eye"></i></button>
                                        <button class="action-btn btn-tracking" data-id="<?= (int)$container['id'] ?>" data-tracking="<?= htmlspecialchars($trackingNumber ?: ($container['tracking_number'] ?? '')) ?>" data-number="<?= htmlspecialchars($container['container_number']) ?>" title="Raad-raac / Tracking">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </button>
                                        <button class="action-btn btn-track" onclick="window.sendWhatsAppToAll(<?= $container['id'] ?>)" title="WhatsApp dhammaan u dir"><i class="fab fa-whatsapp"></i></button>
                                        <?php if (!$isManifestLocked): ?>
                                            <button class="action-btn btn-edit edit-container" data-id="<?= $container['id'] ?>" title="Wax ka beddel"><i class="fas fa-edit"></i></button>
                                            <button class="action-btn btn-delete delete-container" data-id="<?= $container['id'] ?>" data-name="<?= htmlspecialchars($container['container_number']) ?>" title="Tirtir"><i class="fas fa-trash"></i></button>
                                        <?php else: ?>
                                            <button class="action-btn btn-view" disabled style="opacity:0.5;" title="Full/manifest wuu xiran yahay"><i class="fas fa-lock"></i></button>
                                        <?php endif; ?>
                                        <?php if (!$isFinalLocked): ?>
                                            <button class="action-btn btn-status update-status" data-id="<?= $container['id'] ?>" data-status="<?= $container['status'] ?>" title="Beddel Xaaladda"><i class="fas fa-exchange-alt"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </span>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 50px;">
                                <div class="empty-state" style="text-align: center;">
                                    <i class="fas fa-box" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>Kontaynarro lama helin</p>
                                    <button class="btn-primary-custom" id="addContainerBtnEmpty" style="margin-top: 10px;">
                                        <i class="fas fa-plus-circle"></i> Ku dar Kontaynar
                                    </button>
                                </div>
                            </span>
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
                    <a data-page="<?= $page-1 ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;"><i class="fas fa-chevron-left"></i> Hore</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active" style="padding: 8px 14px; border-radius: 8px; background: #2D1859; color: white; border-color: #2D1859;"><?= $i ?></span>
                    <?php else: ?>
                        <a data-page="<?= $i ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a data-page="<?= $page+1 ?>" style="padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer;">Xiga <i class="fas fa-chevron-right"></i></a>
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
    
    elseif ($action === 'get_container') {
        $id = $_POST['id'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, b.branch_name as branch_name
                FROM containers c
                LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
                WHERE c.id = ? AND c.tenant_id = ?
            ");
            $stmt->execute([$id, $session_tenant_id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $manifestStmt = $pdo->prepare("
                SELECT 
                    cmi.id,
                    cust.customer_name,
                    cust.phone,
                    cmi.quantity as total_packages,
                    cmi.cbm_used as total_cbm,
                    COALESCE(cmi.unit_price, ws.unit_price) as cbm_price,
                    (cmi.cbm_used * COALESCE(cmi.unit_price, ws.unit_price)) as total_price,
                    cmi.stock_name as items_list,
                    cmi.added_at,
                    cmi.weight_kg,
                    cmi.storage_fee,
                    ws.id as stock_id
                FROM cargo_manifest_items cmi
                LEFT JOIN warehouse_stock ws ON cmi.warehouse_stock_id = ws.id
                LEFT JOIN customers cust ON ws.customer_id = cust.id AND cust.tenant_id = ws.tenant_id
                WHERE cmi.container_id = ? AND cmi.tenant_id = ?
                ORDER BY cmi.added_at DESC
            ");
            $manifestStmt->execute([$id, $session_tenant_id]);
            $manifest = $manifestStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'container' => $container,
                'manifest' => $manifest
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false, 
                'message' => 'Khalad: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    elseif ($action === 'remove_manifest_item') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT warehouse_stock_id, quantity, container_id, tenant_id FROM cargo_manifest_items WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($item) {
                $containerCheck = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ? LIMIT 1");
                $containerCheck->execute([$item['container_id'], $session_tenant_id]);
                $containerForManifest = $containerCheck->fetch(PDO::FETCH_ASSOC);
                if ($containerForManifest && isContainerManifestLocked($containerForManifest['status'])) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Kontaynarkan wuu dhoofay/full ayuu noqday; manifest-ka lama beddeli karo.']);
                    exit;
                }
                $upd = $pdo->prepare("UPDATE warehouse_stock SET quantity = quantity + ? WHERE id = ? AND tenant_id = ?");
                $upd->execute([$item['quantity'], $item['warehouse_stock_id'], $session_tenant_id]);
                
                $del = $pdo->prepare("DELETE FROM cargo_manifest_items WHERE id = ? AND tenant_id = ?");
                $del->execute([$id, $session_tenant_id]);
                
                $cbmStmt = $pdo->prepare("SELECT COALESCE(SUM(cbm_used), 0) as total_cbm FROM cargo_manifest_items WHERE container_id = ? AND tenant_id = ?");
                $cbmStmt->execute([$item['container_id'], $session_tenant_id]);
                $totalCbm = $cbmStmt->fetch(PDO::FETCH_ASSOC)['total_cbm'];
                
                $updateContainer = $pdo->prepare("UPDATE containers SET size_used_cbm = ? WHERE id = ? AND tenant_id = ?");
                $updateContainer->execute([$totalCbm, $item['container_id'], $session_tenant_id]);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Alaabta waa laga saaray kontaynar-ka, waxaana lagu celiyay bakhaarka.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin.']);
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    elseif ($action === 'delete_manifest_item') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT container_id, tenant_id FROM cargo_manifest_items WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                $containerCheck = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ? LIMIT 1");
                $containerCheck->execute([$item['container_id'], $session_tenant_id]);
                $containerForManifest = $containerCheck->fetch(PDO::FETCH_ASSOC);
                if ($containerForManifest && isContainerManifestLocked($containerForManifest['status'])) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Kontaynarkan wuu dhoofay/full ayuu noqday; alaab lagama tirtiri karo manifest-ka.']);
                    exit;
                }
            }
            
            $del = $pdo->prepare("DELETE FROM cargo_manifest_items WHERE id = ? AND tenant_id = ?");
            $del->execute([$id, $session_tenant_id]);
            
            if ($item) {
                $cbmStmt = $pdo->prepare("SELECT COALESCE(SUM(cbm_used), 0) as total_cbm FROM cargo_manifest_items WHERE container_id = ? AND tenant_id = ?");
                $cbmStmt->execute([$item['container_id'], $session_tenant_id]);
                $totalCbm = $cbmStmt->fetch(PDO::FETCH_ASSOC)['total_cbm'];
                
                $updateContainer = $pdo->prepare("UPDATE containers SET size_used_cbm = ? WHERE id = ? AND tenant_id = ?");
                $updateContainer->execute([$totalCbm, $item['container_id'], $session_tenant_id]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Alaabta si joogto ah ayaa looga tirtiray kontaynar-ka.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    elseif ($action === 'set_container_full') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $check = $pdo->prepare("SELECT id, status FROM containers WHERE id = ? AND tenant_id = ?");
            $check->execute([$id, $session_tenant_id]);
            $containerLock = $check->fetch(PDO::FETCH_ASSOC);
            if (!$containerLock) {
                echo json_encode(['success' => false, 'message' => 'Kontaynar lama helin']);
                exit;
            }
            if (isContainerManifestLocked($containerLock['status'])) {
                echo json_encode(['success' => false, 'message' => 'Kontaynarkan hore ayuu u dhoofay; lama beddeli karo full/open ama alaab cusub laguma dari karo.']);
                exit;
            }
            
            $upd = $pdo->prepare("UPDATE containers SET status = 'ready', updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $upd->execute([$id, $session_tenant_id]);
            
            $pushSql = "
                UPDATE cargo_manifest_items
                SET mogadishu_status        = 'in_warehouse',
                    mogadishu_received_date = NOW()
                WHERE container_id = ? AND tenant_id = ?
            ";
            $pdo->prepare($pushSql)->execute([$id, $session_tenant_id]);

            $wsPushSql = "
                UPDATE warehouse_stock ws
                JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id
                SET ws.mogadishu_status        = 'in_warehouse',
                    ws.mogadishu_received_date = NOW()
                WHERE cmi.container_id = ? AND ws.tenant_id = ?
            ";
            $pdo->prepare($wsPushSql)->execute([$id, $session_tenant_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Kontaynar buuxa ayaa laga dhigay. Dhammaan alaabta waxaa loo diray Bakhaarka Muqdisho.']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    elseif ($action === 'set_container_open') {
        $id = $_POST['id'] ?? 0;
        try {
            $pdo->beginTransaction();
            
            $check = $pdo->prepare("SELECT id, status FROM containers WHERE id = ? AND tenant_id = ?");
            $check->execute([$id, $session_tenant_id]);
            $containerLock = $check->fetch(PDO::FETCH_ASSOC);
            if (!$containerLock) {
                echo json_encode(['success' => false, 'message' => 'Kontaynar lama helin']);
                exit;
            }
            if (isContainerManifestLocked($containerLock['status'])) {
                echo json_encode(['success' => false, 'message' => 'Kontaynarkan hore ayuu u dhoofay; dib looma furi karo xaalad hore.']);
                exit;
            }
            
            $upd = $pdo->prepare("UPDATE containers SET status = 'received', updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $upd->execute([$id, $session_tenant_id]);
            
            $resetSql = "
                UPDATE cargo_manifest_items
                SET mogadishu_status        = 'not_arrived',
                    mogadishu_received_date = NULL
                WHERE container_id = ? AND tenant_id = ? AND mogadishu_status != 'taken'
            ";
            $pdo->prepare($resetSql)->execute([$id, $session_tenant_id]);

            $wsNadiifiSql = "
                UPDATE warehouse_stock ws
                JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id
                SET ws.mogadishu_status        = 'not_arrived',
                    ws.mogadishu_received_date = NULL
                WHERE cmi.container_id = ? AND ws.tenant_id = ? AND ws.mogadishu_status != 'taken'
            ";
            $pdo->prepare($wsNadiifiSql)->execute([$id, $session_tenant_id]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Kontaynar dib ayaa loo furay. Alaabta waa laga saaray Bakhaarka Muqdisho.']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'save_container') {
        $id = $_POST['container_id'] ?? '';
        $tenant_id = $session_tenant_id;
        $container_number = trim($_POST['container_number'] ?? '');
        $container_type = $_POST['container_type'] ?? '20ft';
        $size_cbm = !empty($_POST['size_cbm']) ? (float)$_POST['size_cbm'] : ($container_cbm_map[$container_type] ?? 0);
        $weight_kg = (float)($_POST['weight_kg'] ?? 0);
        $status = $_POST['status'] ?? 'received';
        $current_location = trim($_POST['current_location'] ?? '');
        $current_branch_id = !empty($_POST['current_branch_id']) ? (int)$_POST['current_branch_id'] : null;
        $arrival_date = !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : null;
        $departure_date = !empty($_POST['departure_date']) ? $_POST['departure_date'] : null;
        $estimated_arrival = !empty($_POST['estimated_arrival']) ? $_POST['estimated_arrival'] : null;
        $tracking_number = trim($_POST['tracking_number'] ?? '');
        $seal_number = trim($_POST['seal_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $shipping_line = trim($_POST['shipping_line'] ?? '');
        $bl_number = trim($_POST['bl_number'] ?? '');
        $vessel_name = trim($_POST['vessel_name'] ?? '');
        $port_of_loading = trim($_POST['port_of_loading'] ?? '');
        $port_of_discharge = trim($_POST['port_of_discharge'] ?? '');
        $eta_port = !empty($_POST['eta_port']) ? $_POST['eta_port'] : null;
        $etd_port = !empty($_POST['etd_port']) ? $_POST['etd_port'] : null;
        $customs_status = $_POST['customs_status'] ?? 'pending';
        
        if (empty($container_number)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli lambarka kontaynarka']);
            exit;
        }
        
        try {
            if (empty($id)) {
                $check = $pdo->prepare("SELECT id FROM containers WHERE container_number = ? AND tenant_id = ?");
                $check->execute([$container_number, $tenant_id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Container number '$container_number' horay ayuu uga jiraa shirkaddaada"]);
                    exit;
                }
                
                if (empty($tracking_number)) {
                    $tracking_number = 'TRK-' . date('Ymd') . '-' . rand(1000, 9999);
                }
                
                $sql = "INSERT INTO containers (
                    tenant_id, container_number, container_type, size_cbm, weight_kg, status,
                    current_location, current_branch_id, arrival_date, departure_date, estimated_arrival, tracking_number, 
                    seal_number, notes, shipping_line, bl_number, vessel_name, port_of_loading,
                    port_of_discharge, eta_port, etd_port, customs_status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $tenant_id, $container_number, $container_type, $size_cbm, $weight_kg, $status,
                    $current_location, $current_branch_id, $arrival_date, $departure_date, $estimated_arrival, $tracking_number,
                    $seal_number, $notes, $shipping_line, $bl_number, $vessel_name, $port_of_loading,
                    $port_of_discharge, $eta_port, $etd_port, $customs_status, $_SESSION['user_id']
                ]);
                $container_id = $pdo->lastInsertId();
                
                $trip_number = 'TRP-' . date('ymd') . '-' . str_pad($container_id, 3, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO trucking_trips (tenant_id, container_id, trip_number, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt->execute([$tenant_id, $container_id, $trip_number]);
                
                echo json_encode(['success' => true, 'message' => "Container '$container_number' waa la kaydiyay!"]);
            } else {
                $checkLock = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ?");
                $checkLock->execute([$id, $session_tenant_id]);
                $currentContainer = $checkLock->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentContainer) {
                    echo json_encode(['success' => false, 'message' => 'Kontaynar lama helin ama fasax uma lihid']);
                    exit;
                }
                
                if (isContainerManifestLocked($currentContainer['status'])) {
                    echo json_encode(['success' => false, 'message' => 'Kontaynarkan lama beddeli karo sababtoo ah wuu dhoofay ama waa la gaarsiiyay.']);
                    exit;
                }
                
                $sql = "UPDATE containers 
                        SET container_number=?, container_type=?, size_cbm=?, weight_kg=?, status=?,
                            current_location=?, current_branch_id=?, arrival_date=?, departure_date=?, estimated_arrival=?, tracking_number=?, 
                            seal_number=?, notes=?, shipping_line=?, bl_number=?, vessel_name=?, port_of_loading=?,
                            port_of_discharge=?, eta_port=?, etd_port=?, customs_status=?, updated_at=NOW()
                        WHERE id=? AND tenant_id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $container_number, $container_type, $size_cbm, $weight_kg, $status,
                    $current_location, $current_branch_id, $arrival_date, $departure_date, $estimated_arrival, $tracking_number,
                    $seal_number, $notes, $shipping_line, $bl_number, $vessel_name, $port_of_loading,
                    $port_of_discharge, $eta_port, $etd_port, $customs_status, $id, $session_tenant_id
                ]);
                
                echo json_encode(['success' => true, 'message' => "Container '$container_number' waa la cusboonaysiiyay!"]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_container') {
        $id = (int)($_POST['id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $checkLock = $pdo->prepare("SELECT id, status, container_number FROM containers WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $checkLock->execute([$id, $session_tenant_id]);
            $container = $checkLock->fetch(PDO::FETCH_ASSOC);

            if (!$container) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Kontaynar lama helin']);
                exit;
            }

            // Helper: delete only if table + column exist, si code-ku uusan u jabin database kala duwan.
            $safeDeleteByColumn = function(string $table, string $column, array $values) use ($pdo, $session_tenant_id) {
                if (!$values) return 0;

                $tableCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                $tableCheck->execute([$table]);
                if ((int)$tableCheck->fetchColumn() === 0) return 0;

                $columnCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $columnCheck->execute([$table, $column]);
                if ((int)$columnCheck->fetchColumn() === 0) return 0;

                $values = array_values(array_filter(array_map('intval', $values), fn($v) => $v > 0));
                if (!$values) return 0;

                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $params = $values;

                $hasTenant = false;
                $tenantColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'tenant_id'");
                $tenantColumnCheck->execute([$table]);
                $hasTenant = ((int)$tenantColumnCheck->fetchColumn() > 0);

                $sql = "DELETE FROM `{$table}` WHERE `{$column}` IN ($placeholders)";
                if ($hasTenant) {
                    $sql .= " AND tenant_id = ?";
                    $params[] = $session_tenant_id;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->rowCount();
            };

            // 1) Soo celi tirada alaabta warehouse_stock haddii manifest ku jiray.
            $manifestStmt = $pdo->prepare("\n                SELECT warehouse_stock_id, COALESCE(SUM(quantity), 0) AS qty\n                FROM cargo_manifest_items\n                WHERE container_id = ? AND tenant_id = ? AND warehouse_stock_id IS NOT NULL\n                GROUP BY warehouse_stock_id\n            ");
            $manifestStmt->execute([$id, $session_tenant_id]);
            $manifestItems = $manifestStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($manifestItems as $item) {
                $stockId = (int)($item['warehouse_stock_id'] ?? 0);
                $qtyBack = (int)($item['qty'] ?? 0);
                if ($stockId > 0 && $qtyBack > 0) {
                    $restoreStock = $pdo->prepare("UPDATE warehouse_stock SET quantity = COALESCE(quantity, 0) + ? WHERE id = ? AND tenant_id = ?");
                    $restoreStock->execute([$qtyBack, $stockId, $session_tenant_id]);
                }
            }

            // 2) Hel safarrada ku xiran kontaynarka.
            $tripStmt = $pdo->prepare("SELECT id FROM trucking_trips WHERE container_id = ? AND tenant_id = ?");
            $tripStmt->execute([$id, $session_tenant_id]);
            $tripIds = array_map('intval', $tripStmt->fetchAll(PDO::FETCH_COLUMN));

            // 3) Tirtir records-ka ku xiran safarrada marka hore.
            if ($tripIds) {
                $safeDeleteByColumn('assignments', 'trip_id', $tripIds);
                $safeDeleteByColumn('live_locations', 'trip_id', $tripIds);
                $safeDeleteByColumn('expenses', 'trip_id', $tripIds);
                $safeDeleteByColumn('invoices', 'trip_id', $tripIds);
                $safeDeleteByColumn('cargo_manifest_items', 'shipment_id', $tripIds);
            }

            // 4) Tirtir manifest/logs-ka kontaynarka ku xiran.
            $safeDeleteByColumn('cargo_manifest_items', 'container_id', [$id]);
            $safeDeleteByColumn('whatsapp_container_logs', 'container_id', [$id]);
            $safeDeleteByColumn('whatsapp_tracking_logs', 'container_id', [$id]);
            $safeDeleteByColumn('package_tracking_history', 'container_id', [$id]);
            $safeDeleteByColumn('packages', 'container_id', [$id]);

            // 5) Tirtir safarrada ku xiran kontaynarka.
            $deleteTrips = $pdo->prepare("DELETE FROM trucking_trips WHERE container_id = ? AND tenant_id = ?");
            $deleteTrips->execute([$id, $session_tenant_id]);
            $deletedTrips = $deleteTrips->rowCount();

            // 6) Ugu dambeyn tirtir kontaynarka.
            $deleteContainer = $pdo->prepare("DELETE FROM containers WHERE id = ? AND tenant_id = ?");
            $deleteContainer->execute([$id, $session_tenant_id]);

            if ($deleteContainer->rowCount() <= 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Kontaynar lama tirtirin. Fadlan mar kale isku day.']);
                exit;
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Container '{$container['container_number']}' waa la tirtiray. Safarradii ku xirnaa ($deletedTrips) sidoo kale waa la tirtiray."
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    elseif ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';

        $allowed_statuses = ['received', 'loading', 'loaded', 'shipped', 'dispatched', 'at_port', 'ready', 'delivered'];
        if (!in_array($status, $allowed_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Xaalad aan sax ahayn']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            
            $checkLock = $pdo->prepare("SELECT status FROM containers WHERE id = ? AND tenant_id = ?");
            $checkLock->execute([$id, $session_tenant_id]);
            $currentContainer = $checkLock->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentContainer) {
                echo json_encode(['success' => false, 'message' => 'Kontaynar lama helin']);
                $pdo->rollBack();
                exit;
            }
            
            if (isContainerFinalLocked($currentContainer['status'])) {
                echo json_encode(['success' => false, 'message' => 'Kontaynarkan waa la gaarsiiyay; xaaladdiisa lama beddeli karo.']);
                $pdo->rollBack();
                exit;
            }

            if (!canMoveContainerStatusForward($currentContainer['status'], $status)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Xaalad hore dib looguma celin karo. Xaaladda hadda: ' . somaliContainerXaaladText($currentContainer['status']) . '. Door xaalad ka dambeysa oo keliya.'
                ]);
                $pdo->rollBack();
                exit;
            }

            $stmt = $pdo->prepare("UPDATE containers SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$status, $id, $session_tenant_id]);

            if ($status === 'loaded') {
                $updateTrip = $pdo->prepare("UPDATE trucking_trips SET status = 'loaded', loaded_at = NOW() WHERE container_id = ? AND tenant_id = ? AND status = 'loading'");
                $updateTrip->execute([$id, $session_tenant_id]);
            } elseif ($status === 'shipped') {
                $updateTrip = $pdo->prepare("UPDATE trucking_trips SET status = 'in_transit', departed_at = NOW() WHERE container_id = ? AND tenant_id = ?");
                $updateTrip->execute([$id, $session_tenant_id]);
            } elseif ($status === 'delivered') {
                $updateTrip = $pdo->prepare("UPDATE trucking_trips SET status = 'delivered', delivered_at = NOW() WHERE container_id = ? AND tenant_id = ?");
                $updateTrip->execute([$id, $session_tenant_id]);
            }

            if ($status === 'ready') {
                $pushSql = "
                    UPDATE cargo_manifest_items
                    SET mogadishu_status        = 'in_warehouse',
                        mogadishu_received_date = NOW()
                    WHERE container_id = ? AND tenant_id = ?
                ";
                $pushStmt = $pdo->prepare($pushSql);
                $pushStmt->execute([$id, $session_tenant_id]);
                $pushed = $pushStmt->rowCount();

                $wsPushSql = "
                    UPDATE warehouse_stock ws
                    JOIN cargo_manifest_items cmi ON cmi.warehouse_stock_id = ws.id
                    SET ws.mogadishu_status        = 'in_warehouse',
                        ws.mogadishu_received_date = NOW()
                    WHERE cmi.container_id = ? AND ws.tenant_id = ?
                ";
                $pdo->prepare($wsPushSql)->execute([$id, $session_tenant_id]);

                $pdo->commit();
                $whatsappResult = sendContainerXaaladWhatsAppToMacmiils($pdo, $id, $session_tenant_id, $status);
                $waMsg = isset($whatsappResult['message']) ? ' | ' . $whatsappResult['message'] : '';
                echo json_encode([
                    'success' => true,
                    'message' => "Xaaladda kontaynarka waa la cusboonaysiiyay! $pushed alaab ayaa loo diray Bakhaarka Muqdisho." . $waMsg,
                    'pushed'  => $pushed,
                    'whatsapp' => $whatsappResult
                ]);
            } else {
                if (!isContainerManifestLocked($status)) {
                    $resetSql = "
                        UPDATE cargo_manifest_items
                        SET mogadishu_status        = 'not_arrived',
                            mogadishu_received_date = NULL
                        WHERE container_id = ? AND tenant_id = ? AND mogadishu_status != 'taken'
                    ";
                    $pdo->prepare($resetSql)->execute([$id, $session_tenant_id]);
                }

                $pdo->commit();
                $whatsappResult = sendContainerXaaladWhatsAppToMacmiils($pdo, $id, $session_tenant_id, $status);
                $waMsg = isset($whatsappResult['message']) ? ' | ' . $whatsappResult['message'] : '';
                echo json_encode(['success' => true, 'message' => 'Xaaladda kontaynarka waa la cusboonaysiiyay!' . $waMsg, 'whatsapp' => $whatsappResult]);
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    

    elseif ($action === 'get_tracking_history') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Container sax ah lama helin.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT c.*, b.branch_name
                FROM containers c
                LEFT JOIN branches b ON c.current_branch_id = b.id AND b.tenant_id = c.tenant_id
                WHERE c.id = ? AND c.tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $session_tenant_id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$container) {
                echo json_encode(['success' => false, 'message' => 'Kontaynerka lama helin ama fasax uma lihid.']);
                exit;
            }

            $currentRank = containerStatusRank($container['status'] ?? 'received');
            $statusFlow = [
                'received'   => ['rank' => 1, 'title' => 'La helay', 'icon' => 'fa-box-open'],
                'loading'    => ['rank' => 2, 'title' => 'Waa la rarayaa', 'icon' => 'fa-truck-loading'],
                'loaded'     => ['rank' => 3, 'title' => 'Waa la raray', 'icon' => 'fa-boxes-stacked'],
                'shipped'    => ['rank' => 4, 'title' => 'Wuu dhoofay', 'icon' => 'fa-ship'],
                'dispatched' => ['rank' => 5, 'title' => 'Waa la diray', 'icon' => 'fa-route'],
                'at_port'    => ['rank' => 6, 'title' => 'Dekedda ayuu joogaa', 'icon' => 'fa-anchor'],
                'ready'      => ['rank' => 7, 'title' => 'Diyaar', 'icon' => 'fa-clipboard-check'],
                'delivered'  => ['rank' => 8, 'title' => 'La gaarsiiyay', 'icon' => 'fa-circle-check']
            ];

            $tripStmt = $pdo->prepare("
                SELECT trip_number, status, created_at, loaded_at, departed_at, delivered_at
                FROM trucking_trips
                WHERE container_id = ? AND tenant_id = ?
                ORDER BY created_at ASC
            ");
            $tripStmt->execute([$id, $session_tenant_id]);
            $trips = $tripStmt->fetchAll(PDO::FETCH_ASSOC);
            $firstTrip = $trips[0] ?? [];
            $lastTrip = $trips ? end($trips) : [];

            $statusDates = [
                'received' => $container['created_at'] ?? null,
                'loading' => $firstTrip['created_at'] ?? null,
                'loaded' => $lastTrip['loaded_at'] ?? null,
                'shipped' => $container['departure_date'] ?: ($lastTrip['departed_at'] ?? null),
                'dispatched' => $lastTrip['departed_at'] ?? null,
                'at_port' => $container['eta_port'] ?? null,
                'ready' => $container['arrival_date'] ?: ($container['eta_port'] ?? null),
                'delivered' => $lastTrip['delivered_at'] ?? ($container['arrival_date'] ?? null)
            ];

            $history = [];
            foreach ($statusFlow as $key => $meta) {
                if ($meta['rank'] <= $currentRank) {
                    $rawDate = $statusDates[$key] ?? null;
                    $history[] = [
                        'status' => $key,
                        'title' => $meta['title'],
                        'icon' => $meta['icon'],
                        'passed' => true,
                        'current' => $key === ($container['status'] ?? ''),
                        'date' => ($rawDate && $rawDate !== '0000-00-00' && $rawDate !== '0000-00-00 00:00:00') ? date('d/m/Y H:i', strtotime($rawDate)) : '-',
                        'location' => $container['current_location'] ?: ($container['branch_name'] ?? '-'),
                        'note' => $key === 'shipped' ? 'Kontaynar full ayuu noqday; alaab cusub laguma dari karo.' : ''
                    ];
                }
            }

            $manifestStmt = $pdo->prepare("
                SELECT COUNT(*) AS total_items, COALESCE(SUM(cbm_used),0) AS total_cbm, COALESCE(SUM(quantity),0) AS total_qty
                FROM cargo_manifest_items
                WHERE container_id = ? AND tenant_id = ?
            ");
            $manifestStmt->execute([$id, $session_tenant_id]);
            $manifest = $manifestStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_items' => 0, 'total_cbm' => 0, 'total_qty' => 0];

            echo json_encode([
                'success' => true,
                'container' => [
                    'id' => (int)$container['id'],
                    'container_number' => $container['container_number'],
                    'tracking_number' => $container['tracking_number'],
                    'bl_number' => $container['bl_number'],
                    'status' => $container['status'],
                    'status_text' => somaliContainerXaaladText($container['status']),
                    'current_location' => $container['current_location'] ?: ($container['branch_name'] ?? '-'),
                    'estimated_arrival' => $container['estimated_arrival'],
                    'arrival_date' => $container['arrival_date'],
                    'departure_date' => $container['departure_date'],
                    'vessel_name' => $container['vessel_name'],
                    'port_of_loading' => $container['port_of_loading'],
                    'port_of_discharge' => $container['port_of_discharge']
                ],
                'manifest' => $manifest,
                'history' => $history
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'send_whatsapp_to_container') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Container sax ah lama helin.']);
            exit;
        }

        try {
            $checkStmt = $pdo->prepare("SELECT id, container_number FROM containers WHERE id = ? AND tenant_id = ?");
            $checkStmt->execute([$id, $session_tenant_id]);
            $container = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$container) {
                echo json_encode(['success' => false, 'message' => 'Kontaynerka lama helin ama fasax uma lihid.']);
                exit;
            }

            $whatsappResult = sendContainerXaaladWhatsAppToMacmiils($pdo, $id, $session_tenant_id, null);

            echo json_encode([
                'success' => !empty($whatsappResult['success']) || ((int)($whatsappResult['sent'] ?? 0) > 0),
                'message' => $whatsappResult['message'] ?? 'WhatsApp diris waa dhammaatay.',
                'sent' => (int)($whatsappResult['sent'] ?? 0),
                'failed' => (int)($whatsappResult['failed'] ?? 0),
                'errors' => $whatsappResult['errors'] ?? [],
                'container_number' => $container['container_number']
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $branch_filter = isset($_POST['branch']) ? (int)$_POST['branch'] : 0;
        
        $where = "WHERE tenant_id = ?";
        $params = [$session_tenant_id];
        
        if ($branch_filter > 0) {
            $where .= " AND current_branch_id = ?";
            $params[] = $branch_filter;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received,
                SUM(CASE WHEN status = 'loading' THEN 1 ELSE 0 END) as loading,
                SUM(CASE WHEN status = 'loaded' THEN 1 ELSE 0 END) as loaded,
                SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
                SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
                SUM(CASE WHEN status = 'at_port' THEN 1 ELSE 0 END) as at_port,
                SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                COALESCE(SUM(size_cbm),0) as total_cbm,
                COALESCE(SUM(weight_kg),0) as total_weight
            FROM containers
            $where
        ");
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($stats);
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
    <title>Maareynta Kontaynarada - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
            --curdun-yellow-dark: #D4A70C;
            --curdun-gray: #6c757d;
            --curdun-dark: #2D2D2D;
            --curdun-success: #0F7A3A;
            --curdun-danger: #B42318;
            --curdun-info: #1565c0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .page-header {
            background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light));
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        .page-header h1 i { margin-right: 10px; }
        .page-header .company-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .btn-primary-custom {
            background: var(--curdun-yellow);
            color: var(--curdun-violet);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-primary-custom:hover {
            background: var(--curdun-yellow-dark);
            transform: translateY(-2px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 12px;
            margin-bottom: 25px;
        }
        .stat-card-sm {
            background: white;
            border-radius: 12px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 3px solid var(--curdun-violet);
        }
        .stat-card-sm .stat-info h4 { font-size: 10px; color: var(--curdun-gray); margin: 0 0 3px 0; text-transform: uppercase; }
        .stat-card-sm .stat-info .stat-number { font-size: 18px; font-weight: 700; color: var(--curdun-violet); }
        .stat-card-sm .stat-icon { width: 32px; height: 32px; background: rgba(82,0,102,0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .stat-card-sm .stat-icon i { font-size: 14px; color: var(--curdun-violet); }
        
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 12px; font-weight: 600; color: var(--curdun-gray); margin-bottom: 5px; }
        .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; }
        .btn-reset { background: #f0f0f0; color: var(--curdun-dark); border: none; padding: 8px 20px; border-radius: 8px; margin-left: 10px; cursor: pointer; }
        
        .containers-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
            width: 100%;
        }
        
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-btn { padding: 5px 8px; border-radius: 6px; font-size: 11px; cursor: pointer; border: none; transition: all 0.3s ease; }
        .btn-view { background: #e8eaf6; color: #3949ab; }
        .btn-edit { background: #fff3e0; color: #e65100; }
        .btn-track { background: #e3f2fd; color: #1565c0; }
        .btn-status { background: #fff8e1; color: #ff8f00; }
        .btn-tracking { background: #e3f2fd; color: #1565c0; }
        .btn-tracking:hover { background:#1565c0; color:#fff; transform: translateY(-1px); }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        
        .alert { padding: 12px 20px; border-radius: 8px; position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background: #EEFBF3; color: #0F7A3A; border-left: 4px solid #0F7A3A; }
        .alert-error { background: #FEF0EE; color: #B42318; border-left: 4px solid #B42318; }
        
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; }
        .modal-header .close { color: white; opacity: 1; }
        
        .loading-spinner { text-align: center; padding: 50px; }
        .loading-spinner i { font-size: 48px; color: var(--curdun-violet); animation: spin 1s linear infinite; }
        .empty-state { text-align: center; padding: 50px; color: var(--curdun-gray); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media print {
            body * { visibility: hidden; }
            #viewModalBody, #viewModalBody * { visibility: visible; }
            #viewModalBody { position: absolute; left: 0; top: 0; width: 100%; }
            .modal-header, .modal-footer, .btn, .close, .action-btn { display: none !important; }
        }
        
        .form-group label { font-weight: 600; font-size: 13px; }
        .table th { font-size: 12px; }
        .table td { font-size: 12px; }

        .tracking-summary-card {
            background: linear-gradient(135deg, #2D1859, #4B2C85);
            color: #fff;
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 18px;
            box-shadow: 0 10px 25px rgba(82,0,102,0.16);
        }
        .tracking-summary-card .tracking-code { font-size: 12px; opacity: .88; margin-top: 4px; }
        .tracking-mini-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-top: 14px; }
        .tracking-mini-box { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); border-radius: 12px; padding: 10px; }
        .tracking-mini-box small { display: block; opacity: .75; font-size: 11px; }
        .tracking-mini-box strong { font-size: 13px; }
        .tracking-timeline { position: relative; margin: 0; padding: 0 0 0 22px; list-style: none; }
        .tracking-timeline:before { content: ''; position: absolute; left: 22px; top: 8px; bottom: 8px; width: 3px; background: #e5e7eb; border-radius: 10px; }
        .tracking-step { position: relative; padding: 0 0 18px 36px; }
        .tracking-step:last-child { padding-bottom: 0; }
        .tracking-step-icon { position: absolute; left: -2px; top: 0; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #f3f4f6; color: #6b7280; border: 3px solid #fff; box-shadow: 0 2px 10px rgba(0,0,0,.08); z-index: 2; }
        .tracking-step.done .tracking-step-icon { background: #EEFBF3; color: #0F7A3A; }
        .tracking-step.current .tracking-step-icon { background: #fff8e1; color: #ff8f00; animation: pulseTrack 1.6s infinite; }
        .tracking-step-card { background: #fff; border: 1px solid #edf0f2; border-radius: 14px; padding: 12px 14px; box-shadow: 0 4px 14px rgba(0,0,0,.04); }
        .tracking-step-title { font-weight: 700; color: #2D2D2D; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .tracking-current-badge { background: #fff3cd; color: #8a5a00; border-radius: 999px; padding: 3px 8px; font-size: 10px; font-weight: 700; }
        .tracking-step-meta { margin-top: 6px; color: #6c757d; font-size: 12px; display: flex; flex-wrap: wrap; gap: 10px; }
        .tracking-note { margin-top: 8px; font-size: 12px; color: #856404; background: #fff3cd; padding: 7px 9px; border-radius: 8px; }
        @keyframes pulseTrack { 0% { box-shadow: 0 0 0 0 rgba(255,143,0,.35); } 70% { box-shadow: 0 0 0 10px rgba(255,143,0,0); } 100% { box-shadow: 0 0 0 0 rgba(255,143,0,0); } }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-box"></i> Container Management</h1>
        <div class="d-flex gap-3 align-items-center">
            <span class="company-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <div class="btn-group">
                <button type="button" class="btn-primary-custom" id="addContainerBtn">
                    <i class="fas fa-plus-circle"></i> Ku dar Kontaynar
                </button>
                <a href="?action=export_containers" id="exportContainersBtn" class="btn btn-outline-light ml-2" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3); color: white; border-radius: 8px; padding: 10px 15px; font-weight: 600;">
                    <i class="fas fa-file-csv"></i> CSV soo saar
                </a>
                <a href="?action=download_import_template" class="btn btn-outline-light ml-2" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3); color: white; border-radius: 8px; padding: 10px 15px; font-weight: 600;">
                    <i class="fas fa-download"></i> Template
                </a>
                <button type="button" class="btn btn-outline-light ml-2" id="importContainersBtn" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3); color: white; border-radius: 8px; padding: 10px 15px; font-weight: 600;">
                    <i class="fas fa-file-import"></i> Soo geli CSV
                </button>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card-sm"><div class="stat-info"><h4>Total</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-box"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>La helay</h4><div class="stat-number" id="stat-received">0</div></div><div class="stat-icon"><i class="fas fa-download"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Waa la rarayaa</h4><div class="stat-number" id="stat-loading">0</div></div><div class="stat-icon"><i class="fas fa-spinner"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Waa la raray</h4><div class="stat-number" id="stat-loaded">0</div></div><div class="stat-icon"><i class="fas fa-truck-loading"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Waa la diray</h4><div class="stat-number" id="stat-dispatched">0</div></div><div class="stat-icon"><i class="fas fa-paper-plane"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Dekedda ayuu joogaa</h4><div class="stat-number" id="stat-at_port">0</div></div><div class="stat-icon"><i class="fas fa-ship"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>Diyaar</h4><div class="stat-number" id="stat-ready">0</div></div><div class="stat-icon"><i class="fas fa-check"></i></div></div>
        <div class="stat-card-sm"><div class="stat-info"><h4>La gaarsiiyay</h4><div class="stat-number" id="stat-delivered">0</div></div><div class="stat-icon"><i class="fas fa-flag-checkered"></i></div></div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Raadi</label><input type="text" id="searchInput" placeholder="Container number, Raad-raacing, BL..."></div>
            <div class="filter-group"><label><i class="fas fa-store"></i> Laanta / Goobta</label><select id="branchFilter"><option value="0">Dhammaan Laamaha</option><?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label><i class="fas fa-tag"></i> Xaalad</label><select id="statusFilter"><option value="">Dhammaan</option><option value="received">La helay</option><option value="loading">Waa la rarayaa</option><option value="loaded">Waa la raray</option><option value="shipped">Wuu dhoofay</option><option value="dispatched">Waa la diray</option><option value="at_port">Dekedda ayuu joogaa</option><option value="ready">Diyaar</option><option value="delivered">La gaarsiiyay</option></select></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Nadiifi</button></div>
        </div>
    </div>

    <div id="containers-table-container"><div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i><p>Waa la rarayaa containers...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Create/Edit Container Modal -->
<div class="modal fade" id="containerModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="containerModalLabel"><i class="fas fa-box"></i> Ku dar Kontaynar</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="containerForm">
                <div class="modal-body">
                    <input type="hidden" name="container_id" id="container_id">
                    <input type="hidden" name="tenant_id" value="<?= $session_tenant_id ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Lambarka Kontaynarka <span class="text-danger">*</span></label>
                                <input type="text" name="container_number" id="modalContainerNumber" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Nooca Kontaynar-ka</label>
                                <select name="container_type" id="modalContainerNooca" class="form-control">
                                    <option value="20ft">20 FT (33.2 CBM)</option>
                                    <option value="40ft">40 FT (67.6 CBM)</option>
                                    <option value="40hc">40 HC (76.3 CBM)</option>
                                    <option value="lcl">LCL (Cabbir gaar ah)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Cabbirka (CBM)</label>
                                <input type="number" step="0.01" name="size_cbm" id="modalSizeCbm" class="form-control" value="0">
                                <small class="text-muted">For LCL, enter exact size</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Miisaanka (KG)</label>
                                <input type="number" step="1" name="weight_kg" id="modalMiisaanKg" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Xaalad</label>
                                <select name="status" id="modalXaalad" class="form-control">
                                    <option value="received">La helay</option>
                                    <option value="loading">Waa la rarayaa</option>
                                    <option value="loaded">Waa la raray</option>
                                    <option value="shipped">Wuu dhoofay</option>
                                    <option value="dispatched">Waa la diray</option>
                                    <option value="at_port">Dekedda ayuu joogaa</option>
                                    <option value="ready">Diyaar</option>
                                    <option value="delivered">La gaarsiiyay</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Customs Xaalad</label>
                                <select name="customs_status" id="modalCustomsXaalad" class="form-control">
                                    <option value="pending">Sugaya</option>
                                    <option value="cleared">La fasaxay</option>
                                    <option value="held">La qabtay</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Laanta / Goobta</label>
                                <select name="current_branch_id" id="modalCurrentLaantaId" class="form-control">
                                    <option value="">-- Dooro Laanta --</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Taariikhda Imaanshaha</label>
                                <input type="date" name="arrival_date" id="modalArrivalDate" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Taariikhda Dhoofka</label>
                                <input type="date" name="departure_date" id="modalDepartureDate" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Qiyaasta Imaanshaha</label>
                                <input type="date" name="estimated_arrival" id="modalEstimatedArrival" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Lambarka Raad-raaca</label>
                                <input type="text" name="tracking_number" id="modalRaad-raacingNumber" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>ETA Deked</label>
                                <input type="date" name="eta_port" id="modalEtaPort" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>ETD Deked</label>
                                <input type="date" name="etd_port" id="modalEtdPort" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Lambarka Seal-ka</label>
                                <input type="text" name="seal_number" id="modalSealNumber" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Lambarka BL</label>
                                <input type="text" name="bl_number" id="modalBlNumber" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Goobta Hadda (Qoraal)</label>
                                <input type="text" name="current_location" id="modalCurrentLocation" class="form-control" placeholder="Mogadishu, Hargeisa...">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Magaca Markabka</label>
                                <input type="text" name="vessel_name" id="modalVesselName" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Shirkadda Rarka</label>
                                <input type="text" name="shipping_line" id="modalShippingLine" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dekedda Rarka (POL)</label>
                                <input type="text" name="port_of_loading" id="modalPortOfWaa la rarayaa" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dekedda Dejinta (POD)</label>
                                <input type="text" name="port_of_discharge" id="modalPortOfDischarge" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Qoraallo</label>
                                <textarea name="notes" id="modalQoraallo" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Ka noqo</button>
                    <button type="submit" class="btn btn-primary-custom">Kaydi Container</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Container Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-box"></i> Container Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> Beddel Xaaladda</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="statusForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="statusContainerId">
                    <div class="form-group">
                        <label>Xaaladda Cusub</label>
                        <select name="status" id="statusNewXaalad" class="form-control">
                            <option value="received">La helay</option>
                            <option value="loading">Waa la rarayaa</option>
                            <option value="loaded">Waa la raray</option>
                            <option value="shipped">Wuu dhoofay</option>
                            <option value="dispatched">Waa la diray</option>
                            <option value="at_port">Dekedda ayuu joogaa</option>
                            <option value="ready">Diyaar</option>
                            <option value="delivered">La gaarsiiyay</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Ka noqo</button>
                    <button type="submit" class="btn btn-primary-custom">Cusboonaysii Xaaladda</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Containers Modal -->
<div class="modal fade" id="importContainersModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Soo geli Containers CSV</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="importContainersForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info" style="position:static;min-width:auto;animation:none;">
                        CSV-ga columns-kiisu waa inuu la mid noqdaa template-ka. Haddii aadan hubin, marka hore soo dejiso <strong>Template</strong>.
                    </div>
                    <div class="form-group">
                        <label>Dooro CSV File</label>
                        <input type="file" name="import_file" id="importFile" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <small class="text-muted">Duplicate container_number waa la dhaafayaa, lama gelinayo mar labaad.</small>
                    <div id="importResult" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Ka noqo</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Soo geli</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Manifest Modal (dynamic) -->
<!-- Tracking Timeline Modal -->
<div class="modal fade" id="trackingTimelineModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-map-marked-alt"></i> Raad-raaca Kontaynarka</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="trackingTimelineBody">
                <div class="loading-spinner"><i class="fas fa-spinner"></i><p>Waa la soo gelinayaa...</p></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Action Modal -->
<div class="modal fade" id="confirmActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Xaqiiji Action</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmActionMessage"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">No</button>
                <button type="button" class="btn btn-primary" id="confirmActionBtn">Yes, Proceed</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let currentPage = 1;
let deleteId = null;
const tenantId = <?= $session_tenant_id ?>;

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatDate(dateString) {
    if (!dateString) return '';
    let date = new Date(dateString);
    let year = date.getFullYear();
    let month = String(date.getMonth() + 1).padStart(2, '0');
    let day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function showAlert(type, msg) {
    const placeholder = $('#alert-placeholder');
    if (placeholder.length) {
        placeholder.html(`<div class="alert alert-${type} alert-dismissible fade show"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(5000, function() { $(this).remove(); }), 5000);
    } else {
        alert(msg);
    }
}

function loadContainers() {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax_action: 'get_containers',
            page: currentPage,
            search: $('#searchInput').val(),
            branch: $('#branchFilter').val(),
            status: $('#statusFilter').val()
        },
        dataType: 'json',
        success: function(response) {
            $('#containers-table-container').html(response.table_html);
            $('#pagination-container').html(response.pagination_html);
            attachTableEvents();
        },
        error: function() {
            $('#containers-table-container').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Khalad ayaa dhacay markii xogta la soo load-gareynayay</p></div>');
        }
    });
}

function loadStats() {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { 
            ajax_action: 'get_stats', 
            branch: $('#branchFilter').val()
        },
        dataType: 'json',
        success: function(stats) {
            $('#stat-total').text(stats.total || 0);
            $('#stat-received').text(stats.received || 0);
            $('#stat-loading').text(stats.loading || 0);
            $('#stat-loaded').text(stats.loaded || 0);
            $('#stat-dispatched').text(stats.dispatched || 0);
            $('#stat-at_port').text(stats.at_port || 0);
            $('#stat-ready').text(stats.ready || 0);
            $('#stat-delivered').text(stats.delivered || 0);
        }
    });
}

// ==============================================
// EXPORT MANIFEST TO CSV
// ==============================================
function exportManifestToCSV(containerId, containerNumber) {
    window.location.href = `?action=export_manifest&id=${containerId}`;
}

// ==============================================
// IMPORT MANIFEST FROM CSV
// ==============================================
function importManifestCSV(containerId) {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { ajax_action: 'get_container', id: containerId },
        dataType: 'json',
        success: function(res) {
            if (!res || !res.success) {
                showAlert('error', 'Xogta kontaynar-ka lama helin');
                return;
            }
            
            const c = res.container;
            const lockedStatuses = ['shipped', 'dispatched', 'at_port', 'ready', 'delivered'];
            
            if (lockedStatuses.includes(c.status)) {
                showAlert('error', 'Kontaynarkan wuu dhoofay ama waa la gaarsiiyay; alaab cusub laguma dari karo!');
                return;
            }
            
            const modalHtml = `
                <div class="modal fade" id="importManifestModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-info text-white">
                                <h5 class="modal-title"><i class="fas fa-file-import"></i> Soo geli Manifest CSV - ${escapeHtml(c.container_number)}</h5>
                                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                            </div>
                            <form id="importManifestForm" enctype="multipart/form-data">
                                <div class="modal-body">
                                    <input type="hidden" name="container_id" value="${containerId}">
                                    <div class="alert alert-info">
                                        <strong>CSV Format:</strong><br>
                                        customer_name, phone, stock_name, quantity, cbm_used, weight_kg, unit_price<br>
                                        <small class="text-muted">Tusaale: Ahmed Ali, 612345678, Laptop, 10, 2.5, 50, 100</small>
                                        <hr>
                                        <a href="?action=download_manifest_template" class="btn btn-sm btn-light"><i class="fas fa-download"></i> Soo dejiso Manifest Template</a>
                                    </div>
                                    <div class="form-group">
                                        <label>Dooro CSV File</label>
                                        <input type="file" name="manifest_file" id="manifestFile" class="form-control" accept=".csv,text/csv" required>
                                    </div>
                                    <div id="importManifestResult" class="mt-3"></div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Ka noqo</button>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Soo geli</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            `;
            
            $('#importManifestModal').remove();
            $('body').append(modalHtml);
            $('#importManifestModal').modal('show');
            
            $('#importManifestForm').off('submit').on('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('ajax_action', 'import_manifest_to_container');
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            let html = '<div class="alert alert-success" style="position:static;min-width:auto;animation:none;">' + escapeHtml(resp.message) + '</div>';
                            if (resp.errors && resp.errors.length) {
                                html += '<div class="alert alert-warning" style="position:static;min-width:auto;animation:none;"><strong>Qaar waa la dhaafay:</strong><br>' + resp.errors.map(escapeHtml).join('<br>') + '</div>';
                            }
                            $('#importManifestResult').html(html);
                            setTimeout(() => {
                                $('#importManifestModal').modal('hide');
                                openContainerView(containerId);
                                loadContainers();
                                loadStats();
                            }, 2000);
                        } else {
                            $('#importManifestResult').html('<div class="alert alert-danger" style="position:static;min-width:auto;animation:none;">' + escapeHtml(resp.message) + '</div>');
                        }
                    },
                    error: function() {
                        $('#importManifestResult').html('<div class="alert alert-danger" style="position:static;min-width:auto;animation:none;">Khalad ayaa dhacay intii import la sameynayay.</div>');
                    }
                });
            });
        },
        error: function() {
            showAlert('error', 'Khalad ayaa dhacay markii xogta kontaynar-ka la hubinayay.');
        }
    });
}

// ==============================================
// OPEN CONTAINER VIEW
// ==============================================
function openContainerView(id) {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { ajax_action: 'get_container', id: id },
        dataType: 'json',
        success: function(res) {
            if (!res || res.success === false) {
                showAlert('error', (res ? res.message : 'Xogta kontaynar-ka lama helin'));
                return;
            }
            
            const c = res.container;
            if (!c) {
                showAlert('error', 'Xogta kontaynar-ka lama helin.');
                return;
            }
            
            const manifest = res.manifest || [];
            const statusNames = { 'received':'La helay','loading':'Waa la rarayaa','loaded':'Waa la raray','shipped':'Wuu dhoofay','dispatched':'Waa la diray','at_port':'Dekedda ayuu joogaa','ready':'Diyaar','delivered':'La gaarsiiyay' };
            const lockedStatuses = ['shipped','dispatched','at_port','ready','delivered'];
            const customsXaaladNames = { 'pending': 'Sugaya', 'cleared': 'La fasaxay', 'held': 'La qabtay' };
            
            let totalCbm = 0, totalPkgs = 0, grandTotal = 0, totalMiisaan = 0, totalStorage = 0;
            let manifestHtml = '';
            
            if (manifest && manifest.length > 0) {
                manifestHtml = `
                    <div class="col-12 mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                            <h6 class="mb-0"><i class="fas fa-users"></i> Macaamiisha ku jirta Kontaynarkan</h6>
                            <div class="mt-2 mt-sm-0">
                                <button class="btn btn-sm btn-dark mr-1" onclick="window.print()"><i class="fas fa-print"></i> Daabac</button>
                                <button class="btn btn-sm btn-success mr-1" onclick="exportManifestToCSV(${c.id}, '${c.container_number}')"><i class="fas fa-file-csv"></i> CSV soo saar</button>
                                <button class="btn btn-sm btn-info mr-1" onclick="importManifestCSV(${c.id})"><i class="fas fa-file-import"></i> CSV soo geli</button>
                                ${!lockedStatuses.includes(c.status) ? `<button class="btn btn-sm btn-primary mr-1" onclick="window.location.href='warehouse_stock.php?container_id=${c.id}'"><i class="fas fa-plus"></i> Ku dar Xirmo</button>` : ''}
                                ${!lockedStatuses.includes(c.status) ? 
                                    `<button class="btn btn-sm btn-info mr-1" onclick="confirmAction('set_container_full', ${c.id}, ${c.id})"><i class="fas fa-check-double"></i> Ka dhig Buuxa</button>` : 
                                    (c.status === 'ready' ? `<button class="btn btn-sm btn-warning mr-1" onclick="confirmAction('set_container_open', ${c.id}, ${c.id})"><i class="fas fa-lock-open"></i> Fur Kontaynar</button>` : '')
                                }
                                <button class="btn btn-sm btn-success" onclick="sendWhatsAppToAll(${c.id})"><i class="fab fa-whatsapp"></i> Dhammaan ogeysii</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped" style="font-size: 12px;">
                                <thead style="background-color: #9bc2e6;">
                                    <tr>
                                        <th>Macmiil</th>
                                        <th>Telefoon</th>
                                        <th>Xirmooyin</th>
                                        <th>Wadarta CBM</th>
                                        <th>Miisaanka (KG)</th>
                                        <th>Qiimaha/CBM</th>
                                        <th>Wadarta Qiimaha</th>
                                        <th>Kharashka Kaydinta</th>
                                        <th>Alaab</th>
                                        <th>Hawlo</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                manifest.forEach(m => {
                    totalCbm += parseFloat(m.total_cbm || 0);
                    totalPkgs += parseInt(m.total_packages || 0);
                    grandTotal += parseFloat(m.total_price || 0);
                    totalMiisaan += parseFloat(m.weight_kg || 0);
                    totalStorage += parseFloat(m.storage_fee || 0);
                    manifestHtml += `
                        <tr>
                            <td><strong>${escapeHtml(m.customer_name)}</strong></td>
                            <td>${escapeHtml(m.phone || '-')}</span></td>
                            <td>${m.total_packages}</span></td>
                            <td>${parseFloat(m.total_cbm).toFixed(3)} CBM</span></span></td>
                            <td>${parseFloat(m.weight_kg || 0).toFixed(2)} kg</span></span></td>
                            <td>$${parseFloat(m.cbm_price || 0).toFixed(2)}</span></td>
                            <td><strong>$${parseFloat(m.total_price || 0).toFixed(2)}</strong></span></td>
                            <td>$${parseFloat(m.storage_fee || 0).toFixed(2)}</span></td>
                            <td>${escapeHtml(m.items_list)}</span></td>
                            <td class="text-center">
                                <button class="btn btn-xs btn-success mb-1" onclick="sendWhatsAppToMacmiil('${escapeHtml(m.phone)}', '${escapeHtml(m.customer_name)}', '${escapeHtml(c.container_number)}', '${statusNames[c.status]}')"><i class="fab fa-whatsapp"></i></button>
                                ${!lockedStatuses.includes(c.status) ? `<button class="btn btn-xs btn-warning mb-1" onclick="confirmAction('remove_manifest_item', ${m.id}, ${c.id})"><i class="fas fa-undo"></i></button>
                                <button class="btn btn-xs btn-danger" onclick="confirmAction('delete_manifest_item', ${m.id}, ${c.id})"><i class="fas fa-trash"></i></button>` : ''}
                            </td>
                        </tr>
                    `;
                });
                manifestHtml += `
                                </tbody>
                                <tfoot style="background-color: #ffff00; font-weight: bold;">
                                    <tr>
                                        <td colspan="2" class="text-right">WADAR:</td>
                                        <td>${totalPkgs}</span></td>
                                        <td>${totalCbm.toFixed(3)} CBM</span></span></td>
                                        <td>${totalMiisaan.toFixed(2)} kg</span></span></td>
                                        <td></span></td>
                                        <td><strong>$${grandTotal.toFixed(2)}</strong></span></td>
                                        <td><strong>$${totalStorage.toFixed(2)}</strong></span></td>
                                        <td></span></td>
                                        <td></span></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                manifestHtml = `
                    <div class="col-12 mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0"><i class="fas fa-users"></i> Macaamiisha ku jirta Kontaynarkan</h6>
                            <div>
                                <button class="btn btn-sm btn-info mr-1" onclick="importManifestCSV(${c.id})"><i class="fas fa-file-import"></i> CSV soo geli</button>
                                ${!lockedStatuses.includes(c.status) ? `<button class="btn btn-sm btn-primary" onclick="window.location.href='warehouse_stock.php?container_id=${c.id}'"><i class="fas fa-plus"></i> Ku dar Xirmo</button>` : ''}
                                ${!lockedStatuses.includes(c.status) ? 
                                    `<button class="btn btn-sm btn-info ml-1" onclick="confirmAction('set_container_full', ${c.id}, ${c.id})"><i class="fas fa-check-double"></i> Ka dhig Buuxa</button>` : 
                                    (c.status === 'ready' ? `<button class="btn btn-sm btn-warning ml-1" onclick="confirmAction('set_container_open', ${c.id}, ${c.id})"><i class="fas fa-lock-open"></i> Fur Kontaynar</button>` : '')
                                }
                            </div>
                        </div>
                        <div class="alert alert-warning py-2 mt-2">Weli wax alaab ah laguma rarin kontaynarkan.</div>
                    </div>
                `;
            }

            const capacity = parseFloat(c.size_cbm || 0);
            const percent = capacity > 0 ? Math.min(100, (totalCbm / capacity) * 100).toFixed(1) : 0;
            const remaining = Math.max(0, capacity - totalCbm).toFixed(2);
            const progressColor = percent > 90 ? 'bg-danger' : (percent > 70 ? 'bg-warning' : 'bg-success');

            $('#viewModalBody').html(`
                <div class="row">
                    <div class="col-12 mb-3">
                        <div class="d-flex justify-content-between mb-1"><small><strong>Awoodda Kontaynar-ka (CBM)</strong></small><small><strong>${percent}%</strong></small></div>
                        <div class="progress" style="height: 12px;"><div class="progress-bar ${progressColor}" style="width: ${percent}%"></div></div>
                        <div class="d-flex justify-content-between mt-1" style="font-size: 11px;">
                            <span>La isticmaalay: <strong>${totalCbm.toFixed(2)}</strong></span>
                            <span>Awood: <strong>${capacity.toFixed(2)}</strong></span>
                            <span class="text-info">Harsan: <strong>${remaining}</strong></span>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td width="40%"><strong>Lambarka Kontaynarka:</strong></span></td><td><strong>${escapeHtml(c.container_number)}</strong></span></tr>
                            <tr><td width="40%"><strong>Nooca:</strong></span></td><td>${c.container_type || '20ft'}</span></tr>
                            <tr><td width="40%"><strong>Xaalad:</strong></span></td><td>${statusNames[c.status]}</span></tr>
                            <tr><td width="40%"><strong>Cabbirka (CBM):</strong></span></td><td>${parseFloat(c.size_cbm).toFixed(2)} CBM</span></span></tr>
                            <tr><td width="40%"><strong>Miisaan:</strong></span></td><td>${parseFloat(c.weight_kg || 0).toFixed(2)} KG</span></span></tr>
                            <tr><td width="40%"><strong>Xaaladda Kastamka:</strong></span></td><td>${customsXaaladNames[c.customs_status] || 'Sugaya'}</span></td>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td width="40%"><strong>Laanta:</strong></span></td><td>${escapeHtml(c.branch_name || '-')}</span></tr>
                            <tr><td width="40%"><strong>Lambarka Raad-raaca:</strong></span></td><td>${escapeHtml(c.tracking_number || '-')}</span></tr>
                            <tr><td width="40%"><strong>Lambarka Seal-ka:</strong></span></td><td>${escapeHtml(c.seal_number || '-')}</span></tr>
                            <tr><td width="40%"><strong>Lambarka BL:</strong></span><td><td><code>${escapeHtml(c.bl_number || '-')}</code></span></tr>
                            <tr><td width="40%"><strong>Magaca Markabka:</strong></span></td><td>${escapeHtml(c.vessel_name || '-')}</span></tr>
                            <tr><td width="40%"><strong>Dekedda Rarka:</strong></span></td><td>${escapeHtml(c.port_of_loading || '-')}</span></tr>
                            <tr><td width="40%"><strong>Dekedda Dejinta:</strong></span></td><td>${escapeHtml(c.port_of_discharge || '-')}</span></tr>
                        </table>
                    </div>
                    
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr><td width="40%"><strong>Taariikhda Imaanshaha:</strong></span></td><td>${c.arrival_date ? formatDate(c.arrival_date) : '-'}</span></tr>
                            <tr><td width="40%"><strong>Taariikhda Dhoofka:</strong></span></td><td>${c.departure_date ? formatDate(c.departure_date) : '-'}</span></tr>
                            <tr><td width="40%"><strong>Qiyaasta Imaanshaha:</strong></span></td><td>${c.estimated_arrival ? formatDate(c.estimated_arrival) : '-'}</span></tr>
                            <tr><td width="40%"><strong>ETA Deked:</strong></span></td><td>${c.eta_port ? formatDate(c.eta_port) : '-'}</span></tr>
                            <tr><td width="40%"><strong>ETD Deked:</strong></span></td><td>${c.etd_port ? formatDate(c.etd_port) : '-'}</span></tr>
                            <tr><td width="40%"><strong>Goobta Hadda:</strong></span></td><td>${escapeHtml(c.current_location || '-')}</span></tr>
                        </table>
                    </div>
                    <div class="col-md-12">
                        ${c.notes ? `<div class="alert alert-info mt-2"><strong>Qoraallo:</strong> ${escapeHtml(c.notes)}</div>` : ''}
                    </div>
                    ${manifestHtml}
                </div>
            `);
            $('#viewModal').modal('show');
        },
        error: function() {
            showAlert('error', 'Khalad ayaa dhacay markii faahfaahinta kontaynar-ka la furayay.');
        }
    });
}

function confirmAction(action, id, containerId = null) {
    let message = '';
    let actionData = { ajax_action: action, id: id };
    
    if (action === 'set_container_full') {
        message = 'Ma hubtaa inaad kontaynarkan ka dhigto BUUXA (diyaar u ah bakhaarka)? Alaabtu waxay ka muuqan doontaa Bakhaarka Muqdisho.';
    } else if (action === 'set_container_open') {
        message = 'Ma hubtaa inaad dib u furto kontaynarkan si alaab kale loogu daro?';
    } else if (action === 'remove_manifest_item') {
        message = 'Ma hubtaa inaad alaabtan ka saarto kontaynarka oo bakhaarka ku celiso?';
    } else if (action === 'delete_manifest_item') {
        message = 'Ma hubtaa inaad alaabtan si joogto ah uga tirtirto kontaynarka? Dib looma celin karo.';
    } else if (action === 'delete_container') {
        message = 'Ma hubtaa inaad kontaynarkan si joogto ah u tirtirto?<br><br><div class="alert alert-warning py-1 mb-0"><i class="fas fa-exclamation-triangle"></i> <strong>Digniin:</strong> Haddii kontaynarkan safarro ku xiran yihiin, lama tirtiri karo.</div>';
        actionData = { ajax_action: 'delete_container', id: id };
    }
    
    $('#confirmActionMessage').html(message);
    $('#confirmActionBtn').off('click').on('click', function() {
        $('#confirmActionModal').modal('hide');
        
        if (action === 'delete_container') {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: actionData,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        loadContainers();
                        loadStats();
                        showAlert('success', res.message);
                    } else {
                        showAlert('error', res.message);
                    }
                }
            });
        } else {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: actionData,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        if (containerId) {
                            openContainerView(containerId);
                        }
                        loadContainers();
                        loadStats();
                        showAlert('success', res.message);
                    } else {
                        showAlert('error', res.message);
                    }
                }
            });
        }
    });
    $('#confirmActionModal').modal('show');
}

function sendWhatsAppToAll(containerId) {
    if (!containerId) {
        showAlert('error', 'Container sax ah lama helin.');
        return;
    }

    if (!confirm('Ma hubtaa inaad WhatsApp u dirto dhammaan macaamiisha number-koodu ku jiro kontaynerkan?')) {
        return;
    }

    const $btn = $(`button[onclick="window.sendWhatsAppToAll(${containerId})"], button[onclick="sendWhatsAppToAll(${containerId})"]`);
    const oldHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { ajax_action: 'send_whatsapp_to_container', id: containerId },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                let msg = res.message || 'WhatsApp fariimaha waa la diray.';
                if (typeof res.sent !== 'undefined' || typeof res.failed !== 'undefined') {
                    msg += ` La diray: ${res.sent || 0}, Fashilmay: ${res.failed || 0}`;
                }
                showAlert('success', msg);
            } else {
                let msg = res.message || 'WhatsApp dirista way fashilantay.';
                if (res.errors && res.errors.length) {
                    msg += '<br>' + res.errors.slice(0, 5).map(escapeHtml).join('<br>');
                }
                showAlert('error', msg);
            }
        },
        error: function() {
            showAlert('error', 'Khalad ayaa dhacay. Hubi GreenAPI token, internet/server cURL, iyo numberada macaamiisha.');
        },
        complete: function() {
            $btn.prop('disabled', false).html(oldHtml || '<i class="fab fa-whatsapp"></i>');
        }
    });
}

function openTrackingTimeline(containerId, fallbackTracking, fallbackNumber) {
    $('#trackingTimelineBody').html('<div class="loading-spinner"><i class="fas fa-spinner"></i><p>Waa la soo gelinayaa...</p></div>');
    $('#trackingTimelineModal').modal('show');

    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: { ajax_action: 'get_tracking_history', id: containerId },
        dataType: 'json',
        success: function(res) {
            if (res && res.success) {
                $('#trackingTimelineBody').html(`
                    <div class="tracking-summary-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:10px;">
                            <div>
                                <h5 class="mb-1"><i class="fas fa-shipping-fast"></i> ${escapeHtml(res.container.container_number || '-')}</h5>
                                <div class="tracking-code">Tracking: ${escapeHtml(res.container.tracking_number || '-')} | BL: ${escapeHtml(res.container.bl_number || '-')}</div>
                            </div>
                            <span class="badge badge-light" style="font-size:12px;padding:8px 10px;">${escapeHtml(res.container.status_text || res.container.status || '-')}</span>
                        </div>
                        <div class="tracking-mini-grid">
                            <div class="tracking-mini-box"><small>Goobta hadda</small><strong>${escapeHtml(res.container.current_location || '-')}</strong></div>
                            <div class="tracking-mini-box"><small>Alaabaha</small><strong>${Number(res.manifest.total_items || 0).toLocaleString()} items</strong></div>
                            <div class="tracking-mini-box"><small>Wadarta CBM</small><strong>${Number(res.manifest.total_cbm || 0).toFixed(2)} CBM</strong></div>
                            <div class="tracking-mini-box"><small>Tirada</small><strong>${Number(res.manifest.total_qty || 0).toLocaleString()}</strong></div>
                        </div>
                    </div>
                    <ul class="tracking-timeline">
                `);
                (res.history || []).forEach(step => {
                    const cls = step.current ? 'tracking-step current' : 'tracking-step done';
                    $('#trackingTimelineBody').append(`
                        <li class="${cls}">
                            <div class="tracking-step-icon"><i class="fas ${step.icon || 'fa-circle'}"></i></div>
                            <div class="tracking-step-card">
                                <div class="tracking-step-title">
                                    <span>${escapeHtml(step.title || '-')}</span>
                                    ${step.current ? '<span class="tracking-current-badge">Hadda</span>' : ''}
                                </div>
                                <div class="tracking-step-meta">
                                    <span><i class="far fa-clock"></i> ${escapeHtml(step.date || '-')}</span>
                                    <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(step.location || '-')}</span>
                                </div>
                                ${step.note ? `<div class="tracking-note"><i class="fas fa-info-circle"></i> ${escapeHtml(step.note)}</div>` : ''}
                            </div>
                        </li>
                    `);
                });
                $('#trackingTimelineBody').append('</ul>');
            } else {
                $('#trackingTimelineBody').html(`
                    <div class="alert alert-danger" style="position:static;min-width:auto;animation:none;">
                        ${escapeHtml(res?.message || 'Raad-raaca lama helin.')}
                    </div>
                    <div class="tracking-summary-card">
                        <h5>${escapeHtml(fallbackNumber || '-')}</h5>
                        <div class="tracking-code">Tracking: ${escapeHtml(fallbackTracking || '-')}</div>
                    </div>
                `);
            }
        },
        error: function() {
            $('#trackingTimelineBody').html('<div class="alert alert-danger" style="position:static;min-width:auto;animation:none;">Khalad ayaa dhacay marka raad-raaca la furayay.</div>');
        }
    });
}

function sendWhatsAppToMacmiil(phone, name, containerNo, status) {
    let cleanTelefoon = phone.toString().replace(/\D/g, '');
    if (cleanTelefoon.length === 9 && (cleanTelefoon.startsWith('6') || cleanTelefoon.startsWith('7'))) {
        cleanTelefoon = '252' + cleanTelefoon;
    }
    
    if (!cleanTelefoon) {
        showAlert('error', 'Macmiilkan ma laha telefoon sax ah!');
        return;
    }
    
    const message = `Macmiil ${name},\n\nXaaladda kontaynar-ka *${containerNo}*: *${status}*.\n\nWaad ku mahadsan tahay Cargo Management System.`;
    const url = `https://wa.me/${cleanTelefoon}?text=${encodeURIComponent(message)}`;
    window.open(url, '_blank');
}

function attachTableEvents() {
    $('.view-container').off('click').on('click', function() {
        openContainerView($(this).data('id'));
    });
    
    $('.edit-container').off('click').on('click', function() {
        const id = $(this).data('id');
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_container', id: id },
            dataType: 'json',
            success: function(res) {
                if (!res || !res.success) {
                    showAlert('error', res?.message || 'Xog lama helin');
                    return;
                }
                const c = res.container;
                $('#containerModalLabel').text('Wax ka beddel Kontaynar');
                $('#container_id').val(c.id);
                $('#modalContainerNumber').val(c.container_number);
                $('#modalContainerNooca').val(c.container_type || '20ft');
                $('#modalSizeCbm').val(c.size_cbm);
                $('#modalMiisaanKg').val(c.weight_kg);
                $('#modalXaalad').val(c.status);
                $('#modalCustomsXaalad').val(c.customs_status || 'pending');
                $('#modalCurrentLaantaId').val(c.current_branch_id || '');
                $('#modalCurrentLocation').val(c.current_location || '');
                $('#modalArrivalDate').val(c.arrival_date ? formatDate(c.arrival_date) : '');
                $('#modalDepartureDate').val(c.departure_date ? formatDate(c.departure_date) : '');
                $('#modalEstimatedArrival').val(c.estimated_arrival ? formatDate(c.estimated_arrival) : '');
                $('#modalEtaPort').val(c.eta_port ? formatDate(c.eta_port) : '');
                $('#modalEtdPort').val(c.etd_port ? formatDate(c.etd_port) : '');
                $('#modalRaad-raacingNumber').val(c.tracking_number || '');
                $('#modalSealNumber').val(c.seal_number || '');
                $('#modalBlNumber').val(c.bl_number || '');
                $('#modalVesselName').val(c.vessel_name || '');
                $('#modalShippingLine').val(c.shipping_line || '');
                $('#modalPortOfWaa la rarayaa').val(c.port_of_loading || '');
                $('#modalPortOfDischarge').val(c.port_of_discharge || '');
                $('#modalQoraallo').val(c.notes || '');
                
                if (c.container_type && c.container_type !== 'lcl' && (!c.size_cbm || c.size_cbm == 0)) {
                    const cbmMap = { '20ft': 33.2, '40ft': 67.6, '40hc': 76.3 };
                    if (cbmMap[c.container_type]) {
                        $('#modalSizeCbm').val(cbmMap[c.container_type]);
                    }
                }
                
                $('#containerModal').modal('show');
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay.');
            }
        });
    });
    
    $('.update-status').off('click').on('click', function() {
        const $btn = $(this);
        const currentStatus = String($btn.data('status') || '');
        const ranks = {
            received: 1,
            loading: 2,
            loaded: 3,
            shipped: 4,
            dispatched: 5,
            at_port: 6,
            ready: 7,
            delivered: 8
        };
        const currentRank = ranks[currentStatus] || 0;

        $('#statusContainerId').val($btn.data('id'));
        $('#statusForm').data('triggering-btn', $btn);

        $('#statusNewXaalad option').each(function() {
            const optRank = ranks[$(this).val()] || 0;
            $(this).prop('disabled', optRank <= currentRank);
        });

        const $firstAllowed = $('#statusNewXaalad option:not(:disabled)').first();
        if ($firstAllowed.length) {
            $('#statusNewXaalad').val($firstAllowed.val());
            $('#statusModal').modal('show');
        } else {
            showAlert('warning', 'Kontaynarkan xaaladdiisa hore looma celin karo, xaalad dambe oo la dooran karana ma jirto.');
        }
    });
    
    $('.btn-tracking').off('click').on('click', function() {
        const tracking = $(this).data('tracking') || '';
        const number = $(this).data('number') || '';
        const id = $(this).data('id') || $(this).closest('tr').find('.view-container').data('id');
        openTrackingTimeline(id, tracking, number);
    });
    
    $('.delete-container').off('click').on('click', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        confirmAction('delete_container', id);
    });
    
    $('.pagination a').off('click').on('click', function(e) {
        e.preventDefault();
        currentPage = $(this).data('page');
        loadContainers();
    });
}

// Auto calculate CBM when container type changes
$('#modalContainerNooca').on('change', function() {
    const type = $(this).val();
    const cbmMap = { '20ft': 33.2, '40ft': 67.6, '40hc': 76.3 };
    if (cbmMap[type]) {
        $('#modalSizeCbm').val(cbmMap[type]);
        $('#modalSizeCbm').prop('readonly', true);
    } else {
        $('#modalSizeCbm').prop('readonly', false);
        $('#modalSizeCbm').val('');
    }
});

$(document).ready(function() {
    $('#modalContainerNooca').trigger('change');
    
    const statusColors = {
        'received':   '#17a2b8',
        'loading':    '#ffc107',
        'loaded':     '#28a745',
        'shipped':    '#6f42c1',
        'dispatched': '#fd7e14',
        'at_port':    '#6f42c1',
        'ready':      '#28a745',
        'delivered':  '#20c997'
    };
    const statusLabels = {
        'received':   'La helay',
        'loading':    'Waa la rarayaa',
        'loaded':     'Waa la raray',
        'shipped':    'Wuu dhoofay',
        'dispatched': 'Waa la diray',
        'at_port':    'Dekedda ayuu joogaa',
        'ready':      'Diyaar',
        'delivered':  'La gaarsiiyay'
    };

    $('#statusForm').submit(function(e) {
        e.preventDefault();
        const $form     = $(this);
        const newXaalad = $('#statusNewXaalad').val();
        const $trigBtn  = $form.data('triggering-btn');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $form.serialize() + '&ajax_action=update_status',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#statusModal').modal('hide');

                    if ($trigBtn) {
                        const $row  = $trigBtn.closest('tr');
                        const color = statusColors[newXaalad] || '#6c757d';
                        const label = statusLabels[newXaalad] || newXaalad;

                        $row.find('.status-badge')
                            .css({ 'background': color + '20', 'color': color })
                            .text(label);

                        if (newXaalad === 'ready') {
                            if (!$row.find('.finished-label').length) {
                                $row.find('.status-badge').after(
                                    '<div class="finished-label" style="font-size:10px;color:#28a745;font-weight:600;margin-top:3px;">'
                                    + '<i class="fas fa-check-circle"></i> Waxaa loo diray Bakhaarka</div>'
                                );
                            }
                        } else {
                            $row.find('.finished-label').remove();
                        }

                        if (['shipped', 'dispatched', 'at_port', 'ready', 'delivered'].includes(newXaalad)) {
                            $row.find('.edit-container, .delete-container').remove();
                            if (!$row.find('.manifest-lock-btn').length) {
                                $trigBtn.before(
                                    '<button class="action-btn btn-view manifest-lock-btn" disabled '
                                    + 'style="opacity:0.5;" title="Full/manifest wuu xiran yahay">'
                                    + '<i class="fas fa-lock"></i></button>'
                                );
                            }
                        }

                        if (newXaalad === 'delivered') {
                            $trigBtn.replaceWith(
                                '<button class="action-btn btn-status" disabled '
                                + 'title="La gaarsiiyay — xaaladda lama beddeli karo" '
                                + 'style="opacity:0.4;cursor:not-allowed;">'
                                + '<i class="fas fa-check-double"></i></button>'
                            );
                        } else {
                            $trigBtn.data('status', newXaalad);
                        }
                    }
                    loadStats();
                    showAlert('success', res.message);
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay.');
            }
        });
    });
    
    $('#containerForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: $(this).serialize() + '&ajax_action=save_container',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#containerModal').modal('hide');
                    loadContainers();
                    loadStats();
                    showAlert('success', res.message);
                } else {
                    showAlert('error', res.message);
                }
            },
            error: function() {
                showAlert('error', 'Khalad ayaa dhacay.');
            }
        });
    });
    
    $('#applyFilters').click(function() { 
        currentPage = 1; 
        loadContainers(); 
        loadStats(); 
        
        let search = $('#searchInput').val();
        $('#exportContainersBtn').attr('href', `?action=export_containers&search=${encodeURIComponent(search)}`);
    });
    
    $('#resetFilters').click(function() { 
        $('#searchInput').val(''); 
        $('#branchFilter').val('0');
        $('#statusFilter').val('');
        currentPage = 1; 
        loadContainers(); 
        loadStats(); 
    });
    
    $('#importContainersBtn').click(function() {
        $('#importContainersForm')[0].reset();
        $('#importResult').html('');
        $('#importContainersModal').modal('show');
    });

    $('#importContainersForm').submit(function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax_action', 'import_containers');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    let html = '<div class="alert alert-success" style="position:static;min-width:auto;animation:none;">' + escapeHtml(res.message) + '</div>';
                    if (res.errors && res.errors.length) {
                        html += '<div class="alert alert-warning" style="position:static;min-width:auto;animation:none;"><strong>Qaar waa la dhaafay:</strong><br>' + res.errors.map(escapeHtml).join('<br>') + '</div>';
                    }
                    $('#importResult').html(html);
                    loadContainers();
                    loadStats();
                    setTimeout(() => { $('#importContainersModal').modal('hide'); }, 3000);
                } else {
                    $('#importResult').html('<div class="alert alert-danger" style="position:static;min-width:auto;animation:none;">' + escapeHtml(res.message) + '</div>');
                }
            },
            error: function() {
                $('#importResult').html('<div class="alert alert-danger" style="position:static;min-width:auto;animation:none;">Khalad ayaa dhacay intii import la sameynayay.</div>');
            }
        });
    });

    $('#addContainerBtn, #addContainerBtnEmpty').click(function() {
        $('#containerForm')[0].reset();
        $('#container_id').val('');
        $('#modalContainerNooca').val('20ft').trigger('change');
        $('#modalXaalad').val('received');
        $('#modalCustomsXaalad').val('pending');
        $('#modalCurrentLaantaId').val('');
        $('#containerModalLabel').text('Ku dar Kontaynar');
        $('#containerModal').modal('show');
    });
    
    $('#searchInput').keypress(function(e) {
        if (e.which === 13) {
            currentPage = 1;
            loadContainers();
        }
    });
    
    loadContainers();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>