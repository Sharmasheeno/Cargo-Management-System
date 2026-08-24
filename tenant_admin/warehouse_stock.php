<?php
// tenant_admin/warehouse_stock.php
// Warehouse Stock Management with WhatsApp Tracking - Cargo Management System

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

if (!$session_tenant_id) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

// =====================================================
// FIX: origin_branch_id sync for warehouse_stock + containers
// Sabab: modal-ka "Alaabta ku Rar Kontayner" wuxuu rabaa in
// alaabta iyo kontaynerka ay isku laan/origin_branch_id noqdaan.
// =====================================================
function ensureTableColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

try {
    ensureTableColumn($pdo, 'warehouse_stock', 'origin_branch_id', '`origin_branch_id` INT(11) DEFAULT NULL');
    ensureTableColumn($pdo, 'containers', 'origin_branch_id', '`origin_branch_id` INT(11) DEFAULT NULL AFTER `origin`');

    // Haddii container current_branch_id leeyahay, origin_branch_id ha laga buuxiyo.
    $pdo->exec("
        UPDATE containers
        SET origin_branch_id = current_branch_id
        WHERE origin_branch_id IS NULL
          AND current_branch_id IS NOT NULL
    ");

    // Haddii current_branch_id uusan jirin, ku day in origin text laga waafajiyo branches.
    $pdo->exec("
        UPDATE containers c
        JOIN branches b
          ON b.tenant_id = c.tenant_id
         AND (b.status = 'active' OR b.is_active = 1)
         AND (
              (c.origin = 'china_yiwu' AND (LOWER(b.branch_name) LIKE '%yiwu%' OR LOWER(b.branch_code) LIKE '%yiwu%' OR LOWER(b.branch_name) LIKE '%china%'))
           OR (c.origin = 'china_guangzhou' AND (LOWER(b.branch_name) LIKE '%guangzhou%' OR LOWER(b.branch_code) LIKE '%guangzhou%'))
           OR (c.origin = 'dubai' AND (LOWER(b.branch_name) LIKE '%dubai%' OR LOWER(b.branch_name) LIKE '%dubaai%' OR LOWER(b.branch_code) LIKE '%dubai%'))
           OR (c.origin = 'local' AND (LOWER(b.branch_name) LIKE '%local%' OR b.branch_type IN ('main','office','warehouse')))
         )
        SET c.origin_branch_id = b.id
        WHERE c.origin_branch_id IS NULL
    ");
} catch (Throwable $e) {
    error_log('origin_branch_id auto fix failed: ' . $e->getMessage());
}

function legacyOriginFromBranchName($branchName, $branchCode = '') {
    $text = strtolower((string)$branchName . ' ' . (string)$branchCode);
    if (strpos($text, 'yiwu') !== false) return 'china_yiwu';
    if (strpos($text, 'guangzhou') !== false) return 'china_guangzhou';
    if (strpos($text, 'dubai') !== false || strpos($text, 'dubaai') !== false) return 'dubai';
    return 'local';
}

function branchIconByType($branchType) {
    $map = [
        'main' => '🏢',
        'warehouse' => '🏬',
        'office' => '🏢',
        'store' => '🏪',
        'customs' => '🛃',
        'port' => '⚓'
    ];
    return $map[$branchType] ?? '📍';
}

function somaliContainerStatusText($status) {
    $map = [
        'received' => 'La helay',
        'loading' => 'Waa la rarayaa',
        'loaded' => 'Waa la raray',
        'shipped' => 'Wuu dhoofay',
        'dispatched' => 'Waa la diray',
        'at_port' => 'Wuxuu jooga dekedda',
        'ready' => 'Waa diyaar',
        'delivered' => 'Waa la gaarsiiyay'
    ];
    return $map[$status] ?? $status;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Tenant Admin';

// ==============================================
// GREEN API WHATSAPP CONFIGURATION
// ==============================================
require_once __DIR__ . '/../config/greenapi_config.php';
$GREEN_API_ID = GREEN_API_ID;
$GREEN_API_TOKEN = GREEN_API_TOKEN;
$GREEN_API_URL = GREEN_API_URL;

function formatSomaliPhone($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 9 && ($phone[0] === '6' || $phone[0] === '7')) {
        return '252' . $phone;
    } elseif (strlen($phone) === 10 && ($phone[0] === '6' || $phone[0] === '7')) {
        return '252' . $phone;
    } elseif (strlen($phone) === 12 && substr($phone, 0, 3) === '252') {
        return $phone;
    } else {
        return '252' . ltrim($phone, '0');
    }
}

function sendWhatsAppViaGreenAPI($phone, $message, $idInstance, $apiToken, $apiUrl) {
    $formattedPhone = formatSomaliPhone($phone);
    $chatId = $formattedPhone . '@c.us';
    $url = "{$apiUrl}/waInstance{$idInstance}/SendMessage/{$apiToken}";
    
    $data = ['chatId' => $chatId, 'message' => $message];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($httpCode == 200 && isset($result['idMessage'])) {
        return ['success' => true, 'message_id' => $result['idMessage']];
    } else {
        return ['success' => false, 'error' => $error ?: ($result['message'] ?? 'Unknown error')];
    }
}

// NEW FUNCTION: Send container tracking update with location and ETA
function sendContainerTrackingWhatsApp($pdo, $customer_id, $stock_name, $container_number, $fulcode, $status, $current_location, $eta_date, $tenant_id) {
    global $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL;
    
    try {
        $stmt = $pdo->prepare("SELECT customer_name, phone FROM customers WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$customer_id, $tenant_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer || empty($customer['phone'])) {
            return ['success' => false, 'message' => 'Telefoonka macaamiilka lama helin'];
        }
        
        $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        $company_name = $tenant['name'] ?? 'Shirkadda';
        
        $statusMap = [
            'received' => 'La helay', 'loading' => 'Waa la rarayaa', 'loaded' => 'Waa la raray',
            'shipped' => 'Wuu dhoofay', 'dispatched' => 'Waa la diray', 'at_port' => 'Wuxuu jooga dekedda',
            'ready' => 'Waa diyaar', 'delivered' => 'Waa la gaarsiiyay'
        ];
        $statusText = $statusMap[$status] ?? $status;
        $current_time = date('d/m/Y H:i');
        $etaText = ($eta_date && $eta_date !== '0000-00-00') ? date('d/m/Y', strtotime($eta_date)) : '';
        $message  = "Macmiil: {$customer['customer_name']}\n";
        $message .= "Tracking update\n";
        $message .= "Alaab: {$stock_name}\n";
        $message .= "Container: {$container_number}\n";
        $message .= "Code: {$fulcode}\n";
        $message .= "Location: {$current_location}\n";
        $message .= "Status: {$statusText}\n";
        if ($etaText !== '') $message .= "ETA: {$etaText}\n";
        $message .= "Date: {$current_time}\n";
        $message .= $company_name;

        $result = sendWhatsAppViaGreenAPI($customer['phone'], $message, $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL);
        
        // Log to database
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_tracking_logs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `tenant_id` int(11) NOT NULL,
                `customer_id` int(11) DEFAULT NULL,
                `container_id` int(11) DEFAULT NULL,
                `status` varchar(50) DEFAULT NULL,
                `phone` varchar(20) NOT NULL,
                `message` text NOT NULL,
                `status_sent` varchar(20) DEFAULT 'pending',
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $stmt = $pdo->prepare("INSERT INTO whatsapp_tracking_logs (tenant_id, customer_id, status, phone, message, status_sent, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $sent_status = $result['success'] ? 'sent' : 'failed';
            $stmt->execute([$tenant_id, $customer_id, $status, $customer['phone'], $message, $sent_status]);
        } catch (Exception $e) {
            error_log("Failed to log WhatsApp tracking: " . $e->getMessage());
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("WhatsApp Tracking Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// NEW FUNCTION: Send container arrival notification
function sendContainerArrivalWhatsApp($pdo, $customer_id, $stock_name, $container_number, $fulcode, $arrival_date, $warehouse_location, $tenant_id) {
    global $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL;
    
    try {
        $stmt = $pdo->prepare("SELECT customer_name, phone FROM customers WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$customer_id, $tenant_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer || empty($customer['phone'])) {
            return ['success' => false, 'message' => 'Telefoonka macaamiilka lama helin'];
        }
        
        $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        $company_name = $tenant['name'] ?? 'Shirkadda';
        $current_time = date('d/m/Y H:i');
        $arrival_formatted = date('d/m/Y', strtotime($arrival_date));

        $message  = "Macmiil: {$customer['customer_name']}\n";
        $message .= "Container arrived\n";
        $message .= "Alaab: {$stock_name}\n";
        $message .= "Container: {$container_number}\n";
        $message .= "Code: {$fulcode}\n";
        $message .= "Warehouse: {$warehouse_location}\n";
        $message .= "Arrival: {$arrival_formatted}\n";
        $message .= "Date: {$current_time}\n";
        $message .= $company_name;

        $result = sendWhatsAppViaGreenAPI($customer['phone'], $message, $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL);
        
        return $result;
    } catch (Exception $e) {
        error_log("WhatsApp Arrival Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// NEW FUNCTION: Get container details for tracking
function getContainerTrackingDetails($pdo, $container_id, $tenant_id) {
    $stmt = $pdo->prepare("
        SELECT c.container_number, COALESCE(c.tracking_number, c.container_number) AS fulcode, c.status, c.current_location, 
               c.estimated_arrival as eta, c.arrival_date,
               c.vessel_name, c.bl_number, c.port_of_loading, c.port_of_discharge
        FROM containers c
        WHERE c.id = ? AND c.tenant_id = ?
    ");
    $stmt->execute([$container_id, $tenant_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function sendAutoWhatsAppGreenAPI($pdo, $customer_id, $stock_name, $quantity, $action, $tenant_id, $optional_note = '', $container_id = null, $total_cbm = null, $unit_price = null) {
    global $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL;
    
    try {
        $stmt = $pdo->prepare("SELECT customer_name, phone FROM customers WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$customer_id, $tenant_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer || empty($customer['phone'])) {
            return ['success' => false, 'message' => 'Telefoonka macaamiilka lama helin'];
        }
        
        $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        $company_name = $tenant['name'] ?? 'Shirkadda';
        
        $current_time = date('d/m/Y H:i');
        $message = "";
        
        // Get container details if provided
        $container_info = null;
        if ($container_id) {
            $container_info = getContainerTrackingDetails($pdo, $container_id, $tenant_id);
        }

        // Xisaabinta xogta WhatsApp-ka: CBM, Qiimaha CBM, iyo Qiimaha Guud
        $quantity_num = (float)($quantity ?? 0);
        $total_cbm_num = ($total_cbm !== null && $total_cbm !== '') ? (float)$total_cbm : 0.0;
        $unit_price_num = ($unit_price !== null && $unit_price !== '') ? (float)$unit_price : 0.0;
        $total_amount_num = $total_cbm_num * $unit_price_num;

        $fmt_qty = rtrim(rtrim(number_format($quantity_num, 2, '.', ''), '0'), '.');
        $fmt_cbm = number_format($total_cbm_num, 3, '.', '');
        $fmt_unit_price = number_format($unit_price_num, 2, '.', ',');
        $fmt_total_amount = number_format($total_amount_num, 2, '.', ',');

        $statusMapShort = [
            'added' => 'Alaab cusub',
            'loaded' => 'La raray',
            'shipped' => 'Wuu dhoofay',
            'at_port' => 'Dekedda timid',
            'ready' => 'Diyaar',
            'delivered' => 'La gaarsiiyay',
            'removed' => 'Laga saaray'
        ];

        $containerText = '';
        if ($container_info && !empty($container_info['container_number'])) {
            $containerText = (string)$container_info['container_number'];
        }

        $message = buildShortStockWhatsAppMessage([
            'company' => $company_name,
            'customer' => $customer['customer_name'],
            'item' => $stock_name,
            'qty' => $fmt_qty,
            'cbm' => $fmt_cbm,
            'rate' => $fmt_unit_price,
            'total' => $fmt_total_amount,
            'status' => $statusMapShort[$action] ?? $action,
            'container' => $containerText,
            'date' => $current_time
        ]);

        if ($action === 'removed' && !empty($optional_note)) {
            $message .= "\nReason: " . $optional_note;
        }

        $result = sendWhatsAppViaGreenAPI($customer['phone'], $message, $GREEN_API_ID, $GREEN_API_TOKEN, $GREEN_API_URL);
        
        // Log to database
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `tenant_id` int(11) NOT NULL,
                `customer_id` int(11) DEFAULT NULL,
                `action` varchar(20) NOT NULL,
                `phone` varchar(20) NOT NULL,
                `message` text NOT NULL,
                `status` varchar(20) DEFAULT 'pending',
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $stmt = $pdo->prepare("INSERT INTO whatsapp_logs (tenant_id, customer_id, action, phone, message, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $status = $result['success'] ? 'sent' : 'failed';
            $stmt->execute([$tenant_id, $customer_id, $action, $customer['phone'], $message, $status]);
        } catch (Exception $e) {
            error_log("Failed to log WhatsApp: " . $e->getMessage());
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("WhatsApp Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Get tenant name
$tenant_name = '';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$session_tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_name = $tenant['name'] ?? 'Shirkadeyda';
} catch (PDOException $e) {
    $tenant_name = 'Shirkadeyda';
}

// Get customers
$customers = [];
try {
    $stmt = $pdo->prepare("SELECT id, customer_name, phone FROM customers WHERE tenant_id = ? ORDER BY customer_name");
    $stmt->execute([$session_tenant_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

// Get origin branches/locations from branches table
$origin_branches = [];
try {
    $stmt = $pdo->prepare("SELECT id, branch_name, branch_type, branch_code FROM branches WHERE tenant_id = ? AND (status = 'active' OR is_active = 1) ORDER BY branch_name ASC");
    $stmt->execute([$session_tenant_id]);
    $origin_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $origin_branches = [];
}


// ==============================================
// DUPLICATE PROTECTION + SAFE HELPERS
// ==============================================
function normalizeTextKey($value): string {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value);
    return mb_strtolower($value, 'UTF-8');
}

function normalizePhoneKey($phone): string {
    return preg_replace('/\D/', '', (string)$phone);
}

function decimalPostValue(string $key, float $default = 0.0): float {
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) return $default;
    $value = str_replace(',', '.', trim((string)$value));
    return is_numeric($value) ? (float)$value : $default;
}

function intPostValue(string $key, int $default = 0): int {
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) return $default;
    return is_numeric($value) ? (int)$value : $default;
}

function findDuplicateStockId(PDO $pdo, int $tenantId, ?int $customerId, ?int $originBranchId, string $stockName, string $location, ?int $excludeId = null): ?int {
    $stockNameKey = normalizeTextKey($stockName);
    $locationKey = normalizeTextKey($location);

    $sql = "
        SELECT id
        FROM warehouse_stock
        WHERE tenant_id = :tenant_id
          AND LOWER(TRIM(stock_name)) = :stock_name
          AND COALESCE(customer_id, 0) = COALESCE(:customer_id, 0)
          AND COALESCE(origin_branch_id, 0) = COALESCE(:origin_branch_id, 0)
          AND LOWER(TRIM(COALESCE(location, ''))) = :location
    ";

    $params = [
        ':tenant_id' => $tenantId,
        ':stock_name' => $stockNameKey,
        ':customer_id' => $customerId,
        ':origin_branch_id' => $originBranchId,
        ':location' => $locationKey
    ];

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= " AND id <> :exclude_id";
        $params[':exclude_id'] = $excludeId;
    }

    $sql .= " ORDER BY id ASC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);

    return $found ? (int)$found['id'] : null;
}

function updateExistingStockInsteadOfDuplicate(
    PDO $pdo,
    int $existingId,
    int $tenantId,
    ?int $customerId,
    ?int $originBranchId,
    string $origin,
    string $stockName,
    int $quantityToAdd,
    float $lengthCm,
    float $widthCm,
    float $heightCm,
    float $volumeCbm,
    string $location,
    int $minimumStock,
    int $maximumStock,
    float $unitPrice,
    int $userId
): void {
    $stmt = $pdo->prepare("
        UPDATE warehouse_stock
        SET customer_id = :customer_id,
            origin_branch_id = :origin_branch_id,
            origin = :origin,
            stock_name = :stock_name,
            quantity = COALESCE(quantity, 0) + :quantity_to_add,
            length_cm = :length_cm,
            width_cm = :width_cm,
            height_cm = :height_cm,
            volume_cbm = :volume_cbm,
            location = :location,
            minimum_stock = :minimum_stock,
            maximum_stock = :maximum_stock,
            unit_price = :unit_price,
            updated_by = :updated_by,
            last_updated = NOW()
        WHERE id = :id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        ':customer_id' => $customerId,
        ':origin_branch_id' => $originBranchId,
        ':origin' => $origin,
        ':stock_name' => $stockName,
        ':quantity_to_add' => $quantityToAdd,
        ':length_cm' => $lengthCm,
        ':width_cm' => $widthCm,
        ':height_cm' => $heightCm,
        ':volume_cbm' => $volumeCbm,
        ':location' => $location,
        ':minimum_stock' => $minimumStock,
        ':maximum_stock' => $maximumStock,
        ':unit_price' => $unitPrice,
        ':updated_by' => $userId,
        ':id' => $existingId,
        ':tenant_id' => $tenantId
    ]);
}

function insertStockSafely(
    PDO $pdo,
    int $tenantId,
    ?int $customerId,
    ?int $originBranchId,
    string $origin,
    string $stockName,
    int $quantity,
    float $lengthCm,
    float $widthCm,
    float $heightCm,
    float $volumeCbm,
    string $location,
    int $minimumStock,
    int $maximumStock,
    float $unitPrice,
    int $userId
): int {
    $stmt = $pdo->prepare("
        INSERT INTO warehouse_stock
            (tenant_id, customer_id, origin_branch_id, origin, stock_name, quantity,
             length_cm, width_cm, height_cm, volume_cbm, location, minimum_stock,
             maximum_stock, unit_price, updated_by, last_updated)
        VALUES
            (:tenant_id, :customer_id, :origin_branch_id, :origin, :stock_name, :quantity,
             :length_cm, :width_cm, :height_cm, :volume_cbm, :location, :minimum_stock,
             :maximum_stock, :unit_price, :updated_by, NOW())
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
        ':origin_branch_id' => $originBranchId,
        ':origin' => $origin,
        ':stock_name' => $stockName,
        ':quantity' => $quantity,
        ':length_cm' => $lengthCm,
        ':width_cm' => $widthCm,
        ':height_cm' => $heightCm,
        ':volume_cbm' => $volumeCbm,
        ':location' => $location,
        ':minimum_stock' => $minimumStock,
        ':maximum_stock' => $maximumStock,
        ':unit_price' => $unitPrice,
        ':updated_by' => $userId
    ]);

    return (int)$pdo->lastInsertId();
}

function buildShortStockWhatsAppMessage(array $data): string {
    $company = $data['company'] ?? 'Shirkadda';
    $customer = $data['customer'] ?? 'Macaamiil';
    $item = $data['item'] ?? '-';
    $qty = $data['qty'] ?? '-';
    $cbm = $data['cbm'] ?? '0.000';
    $rate = $data['rate'] ?? '0.00';
    $total = $data['total'] ?? '0.00';
    $status = $data['status'] ?? '-';
    $container = $data['container'] ?? '';
    $date = $data['date'] ?? date('d/m/Y H:i');

    $message  = "Macmiil: {$customer}\n";
    $message .= "Status: {$status}\n";
    $message .= "Alaab: {$item}\n";
    $message .= "Qty: {$qty}\n";
    if ((float)$cbm > 0) $message .= "CBM: {$cbm}\n";
    if ((float)$rate > 0) $message .= "Rate: \${$rate}\n";
    if ((float)$total > 0) $message .= "Total: \${$total}\n";
    if ($container !== '') $message .= "Container: {$container}\n";
    $message .= "Date: {$date}\n";
    $message .= $company;

    return $message;
}

function findOrCreateCustomerNoDuplicate(PDO $pdo, int $tenantId, string $customerName, string $customerPhone): ?int {
    $customerName = trim($customerName);
    $customerPhone = trim($customerPhone);
    $phoneKey = normalizePhoneKey($customerPhone);

    if ($phoneKey !== '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM customers
            WHERE tenant_id = ?
              AND REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '_', '') = ?
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $phoneKey]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found) return (int)$found['id'];
    }

    if ($customerName === '' && $customerPhone === '') {
        return null;
    }

    if ($customerName === '') {
        $customerName = 'Macaamiil';
    }

    $stmt = $pdo->prepare("
        INSERT INTO customers (tenant_id, customer_name, phone, is_active, created_at)
        VALUES (?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$tenantId, $customerName, $customerPhone]);

    return (int)$pdo->lastInsertId();
}


// Handle Export/Template Actions (GET)
if (isset($_GET['action'])) {
    $get_action = $_GET['action'];

    if ($get_action === 'export_stock') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=warehouse_stock_export_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'id',
            'customer_name',
            'customer_phone',
            'origin_branch_id',
            'origin_branch_name',
            'origin_branch_code',
            'origin',
            'stock_name',
            'quantity',
            'length_cm',
            'width_cm',
            'height_cm',
            'volume_cbm',
            'location',
            'minimum_stock',
            'maximum_stock',
            'unit_price',
            'last_updated'
        ]);

        $where_conditions = ["ws.tenant_id = ?"];
        $params = [$session_tenant_id];

        $search = trim($_GET['search'] ?? '');
        $origin_branch_id = $_GET['origin_branch_id'] ?? ($_GET['origin'] ?? 'all');
        $low_stock_only = isset($_GET['low_stock_only']) ? (int)$_GET['low_stock_only'] : 0;

        if ($search !== '') {
            $where_conditions[] = "(ws.stock_name LIKE ? OR ws.location LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ? OR b.branch_name LIKE ?)";
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like, $like);
        }

        if ($origin_branch_id !== 'all' && $origin_branch_id !== '') {
            $where_conditions[] = "ws.origin_branch_id = ?";
            $params[] = (int)$origin_branch_id;
        }

        if ($low_stock_only === 1) {
            $where_conditions[] = "ws.quantity <= ws.minimum_stock";
        }

        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        $sql = "
            SELECT 
                ws.id,
                c.customer_name,
                c.phone AS customer_phone,
                ws.origin_branch_id,
                b.branch_name AS origin_branch_name,
                b.branch_code AS origin_branch_code,
                ws.origin,
                ws.stock_name,
                ws.quantity,
                ws.length_cm,
                ws.width_cm,
                ws.height_cm,
                ws.volume_cbm,
                ws.location,
                ws.minimum_stock,
                ws.maximum_stock,
                ws.unit_price,
                ws.last_updated
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id AND c.tenant_id = ws.tenant_id
            LEFT JOIN branches b ON ws.origin_branch_id = b.id AND b.tenant_id = ws.tenant_id
            $where_clause
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

    if ($get_action === 'download_stock_import_template') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=warehouse_stock_import_template.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'customer_phone',
            'customer_name',
            'origin_branch_id',
            'origin_branch_name',
            'origin_branch_code',
            'stock_name',
            'quantity',
            'length_cm',
            'width_cm',
            'height_cm',
            'volume_cbm',
            'location',
            'minimum_stock',
            'maximum_stock',
            'unit_price'
        ]);

        fputcsv($output, [
            '25261XXXXXXX',
            'Magaca Macaamiilka',
            '',
            'Yiwu Warehouse',
            'YW',
            'Dharka',
            '10',
            '50',
            '40',
            '30',
            '',
            'Shelf A1',
            '2',
            '100',
            '5.00'
        ]);

        fclose($output);
        exit;
    }
}


// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    if ($action === 'search_customers') {
        $q = trim($_POST['q'] ?? '');
        $results = [];

        if ($q !== '') {
            try {
                $like = '%' . $q . '%';
                $stmt = $pdo->prepare("
                    SELECT id, customer_name, phone, COALESCE(balance, 0) AS balance, COALESCE(loyalty_points, 0) AS loyalty_points
                    FROM customers
                    WHERE tenant_id = ?
                      AND is_active = 1
                      AND (customer_name LIKE ? OR phone LIKE ?)
                    ORDER BY customer_name ASC
                    LIMIT 20
                ");
                $stmt->execute([$session_tenant_id, $like, $like]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                try {
                    $like = '%' . $q . '%';
                    $stmt = $pdo->prepare("
                        SELECT id, customer_name, phone
                        FROM customers
                        WHERE tenant_id = ?
                          AND (customer_name LIKE ? OR phone LIKE ?)
                        ORDER BY customer_name ASC
                        LIMIT 20
                    ");
                    $stmt->execute([$session_tenant_id, $like, $like]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($results as &$r) {
                        $r['balance'] = 0;
                        $r['loyalty_points'] = 0;
                    }
                } catch (PDOException $ignore) {
                    $results = [];
                }
            }
        }

        echo json_encode(['success' => true, 'customers' => $results]);
        exit;
    }


    if ($action === 'import_stock') {
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

        $inserted = 0;
        $updated = 0;
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

                $stock_id = !empty($data['id']) ? (int)$data['id'] : 0;
                $stock_name = trim($data['stock_name'] ?? '');
                if ($stock_name === '') {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: stock_name waa madhan yahay.";
                    continue;
                }

                $customer_phone = trim($data['customer_phone'] ?? '');
                $customer_name = trim($data['customer_name'] ?? '');
                $customer_id = findOrCreateCustomerNoDuplicate($pdo, (int)$session_tenant_id, $customer_name, $customer_phone);

                $origin_branch_id = null;
                if (!empty($data['origin_branch_id'])) {
                    $branchStmt = $pdo->prepare("SELECT id, branch_name, branch_code FROM branches WHERE id = ? AND tenant_id = ? AND (status = 'active' OR is_active = 1) LIMIT 1");
                    $branchStmt->execute([(int)$data['origin_branch_id'], $session_tenant_id]);
                    $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);
                    if ($branch) {
                        $origin_branch_id = (int)$branch['id'];
                    }
                }

                if (!$origin_branch_id && !empty($data['origin_branch_code'])) {
                    $branchStmt = $pdo->prepare("SELECT id, branch_name, branch_code FROM branches WHERE tenant_id = ? AND branch_code = ? AND (status = 'active' OR is_active = 1) LIMIT 1");
                    $branchStmt->execute([$session_tenant_id, $data['origin_branch_code']]);
                    $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);
                    if ($branch) {
                        $origin_branch_id = (int)$branch['id'];
                    }
                }

                if (!$origin_branch_id && !empty($data['origin_branch_name'])) {
                    $branchStmt = $pdo->prepare("SELECT id, branch_name, branch_code FROM branches WHERE tenant_id = ? AND branch_name = ? AND (status = 'active' OR is_active = 1) LIMIT 1");
                    $branchStmt->execute([$session_tenant_id, $data['origin_branch_name']]);
                    $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);
                    if ($branch) {
                        $origin_branch_id = (int)$branch['id'];
                    }
                }

                if (!$origin_branch_id) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: laanta/asalka lama helin. Geli origin_branch_id, origin_branch_code ama origin_branch_name sax ah.";
                    continue;
                }

                $branchStmt = $pdo->prepare("SELECT branch_name, branch_code FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1");
                $branchStmt->execute([$origin_branch_id, $session_tenant_id]);
                $branchInfo = $branchStmt->fetch(PDO::FETCH_ASSOC);
                $origin = $branchInfo ? legacyOriginFromBranchName($branchInfo['branch_name'], $branchInfo['branch_code'] ?? '') : 'local';

                $quantity = isset($data['quantity']) && $data['quantity'] !== '' ? (int)$data['quantity'] : 0;
                $length_cm = isset($data['length_cm']) && $data['length_cm'] !== '' ? (float)$data['length_cm'] : 0;
                $width_cm = isset($data['width_cm']) && $data['width_cm'] !== '' ? (float)$data['width_cm'] : 0;
                $height_cm = isset($data['height_cm']) && $data['height_cm'] !== '' ? (float)$data['height_cm'] : 0;
                $volume_cbm = isset($data['volume_cbm']) && $data['volume_cbm'] !== '' ? (float)$data['volume_cbm'] : 0;

                if ($volume_cbm <= 0 && $length_cm > 0 && $width_cm > 0 && $height_cm > 0) {
                    $volume_cbm = ($origin === 'dubai')
                        ? (($length_cm * $width_cm * $height_cm) * 0.0283168)
                        : (($length_cm * $width_cm * $height_cm) / 1000000);
                }

                $location = trim($data['location'] ?? '');
                $minimum_stock = isset($data['minimum_stock']) && $data['minimum_stock'] !== '' ? (int)$data['minimum_stock'] : 0;
                $maximum_stock = isset($data['maximum_stock']) && $data['maximum_stock'] !== '' ? (int)$data['maximum_stock'] : 0;
                $unit_price = isset($data['unit_price']) && $data['unit_price'] !== '' ? (float)$data['unit_price'] : 0;

                if ($stock_id > 0) {
                    $checkStock = $pdo->prepare("SELECT id FROM warehouse_stock WHERE id = ? AND tenant_id = ? LIMIT 1");
                    $checkStock->execute([$stock_id, $session_tenant_id]);
                    if ($checkStock->fetch(PDO::FETCH_ASSOC)) {
                        $duplicateId = findDuplicateStockId($pdo, (int)$session_tenant_id, $customer_id, $origin_branch_id, $stock_name, $location, $stock_id);
                        if ($duplicateId) {
                            $skipped++;
                            $errors[] = "Row {$rowNumber}: duplicate ayuu noqon lahaa, record hore ID {$duplicateId} ayaa jira.";
                            continue;
                        }

                        $upd = $pdo->prepare("
                            UPDATE warehouse_stock
                            SET customer_id = :customer_id,
                                origin_branch_id = :origin_branch_id,
                                origin = :origin,
                                stock_name = :stock_name,
                                quantity = :quantity,
                                length_cm = :length_cm,
                                width_cm = :width_cm,
                                height_cm = :height_cm,
                                volume_cbm = :volume_cbm,
                                location = :location,
                                minimum_stock = :minimum_stock,
                                maximum_stock = :maximum_stock,
                                unit_price = :unit_price,
                                updated_by = :updated_by,
                                last_updated = NOW()
                            WHERE id = :id
                              AND tenant_id = :tenant_id
                        ");
                        $upd->execute([
                            ':customer_id' => $customer_id,
                            ':origin_branch_id' => $origin_branch_id,
                            ':origin' => $origin,
                            ':stock_name' => $stock_name,
                            ':quantity' => $quantity,
                            ':length_cm' => $length_cm,
                            ':width_cm' => $width_cm,
                            ':height_cm' => $height_cm,
                            ':volume_cbm' => $volume_cbm,
                            ':location' => $location,
                            ':minimum_stock' => $minimum_stock,
                            ':maximum_stock' => $maximum_stock,
                            ':unit_price' => $unit_price,
                            ':updated_by' => (int)$_SESSION['user_id'],
                            ':id' => $stock_id,
                            ':tenant_id' => (int)$session_tenant_id
                        ]);
                        $updated++;
                        continue;
                    }
                }

                $duplicateId = findDuplicateStockId($pdo, (int)$session_tenant_id, $customer_id, $origin_branch_id, $stock_name, $location);
                if ($duplicateId) {
                    updateExistingStockInsteadOfDuplicate(
                        $pdo,
                        $duplicateId,
                        (int)$session_tenant_id,
                        $customer_id,
                        $origin_branch_id,
                        $origin,
                        $stock_name,
                        $quantity,
                        $length_cm,
                        $width_cm,
                        $height_cm,
                        $volume_cbm,
                        $location,
                        $minimum_stock,
                        $maximum_stock,
                        $unit_price,
                        (int)$_SESSION['user_id']
                    );
                    $updated++;
                    continue;
                }

                insertStockSafely(
                    $pdo,
                    (int)$session_tenant_id,
                    $customer_id,
                    $origin_branch_id,
                    $origin,
                    $stock_name,
                    $quantity,
                    $length_cm,
                    $width_cm,
                    $height_cm,
                    $volume_cbm,
                    $location,
                    $minimum_stock,
                    $maximum_stock,
                    $unit_price,
                    (int)$_SESSION['user_id']
                );
                $inserted++;
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

    if ($action === 'quick_add_customer') {
        $tenant_id = $session_tenant_id;
        $name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Magaca macaamiilka waa lagama maarmaan']);
            exit;
        }
        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Telefoonka waa lagama maarmaan']);
            exit;
        }

        try {
            $chk = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND phone = ?");
            $chk->execute([$tenant_id, $phone]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Macaamiil leh telefoonkan horay ayuu u jiraa']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, customer_name, phone, email, address, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$tenant_id, $name, $phone, $email, $address]);
            $new_id = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name, 'phone' => $phone]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'quick_add_trip') {
        $tenant_id = $session_tenant_id;
        $container_number = trim($_POST['container_number'] ?? '');
        $container_type = trim($_POST['container_type'] ?? '20ft');
        $trip_number = trim($_POST['trip_number'] ?? '');
        $origin_branch_id = !empty($_POST['origin'] ?? '') ? (int)$_POST['origin'] : 0;
        $origin = 'local';
        if ($origin_branch_id) {
            $branchCheck = $pdo->prepare("SELECT branch_name, branch_code FROM branches WHERE id = ? AND tenant_id = ? AND (status = 'active' OR is_active = 1)");
            $branchCheck->execute([$origin_branch_id, $tenant_id]);
            $branchInfo = $branchCheck->fetch(PDO::FETCH_ASSOC);
            if ($branchInfo) { $origin = legacyOriginFromBranchName($branchInfo['branch_name'], $branchInfo['branch_code'] ?? ''); }
        }

        if (empty($container_number)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli lambarka kontaynerka']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Haddii kontaynerkan hore u jiray, ha gelin mar kale si looga fogaado duplicate key: uk_container_number
            $checkContainer = $pdo->prepare("SELECT id, container_number FROM containers WHERE tenant_id = ? AND container_number = ? LIMIT 1");
            $checkContainer->execute([$tenant_id, $container_number]);
            $existingContainer = $checkContainer->fetch(PDO::FETCH_ASSOC);

            if ($existingContainer) {
                $container_id = (int)$existingContainer['id'];

                // Cusboonaysii xogta fudud haddii loo baahdo, laakiin ha jabin record hore.
                // Muhiim: origin_branch_id waa in la kaydiyaa si container select-ku u soo saaro kaliya laanta saxda ah.
                $updContainer = $pdo->prepare("
                    UPDATE containers
                    SET container_type = COALESCE(NULLIF(?, ''), container_type),
                        origin = ?,
                        origin_branch_id = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                ");
                $updContainer->execute([$container_type, $origin, $origin_branch_id ?: null, $container_id, $tenant_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO containers
                        (tenant_id, container_number, container_type, origin, origin_branch_id, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'received', NOW())
                ");
                $stmt->execute([$tenant_id, $container_number, $container_type, $origin, $origin_branch_id ?: null]);
                $container_id = (int)$pdo->lastInsertId();
            }

            if (empty($trip_number)) {
                $trip_number = 'TRP-' . date('ymdHis') . '-' . $container_id;
            }

            // Haddii trip_number la bixiyay oo hore u jiray, samee mid cusub si duplicate uusan u dhicin.
            $tripCheck = $pdo->prepare("SELECT id FROM trucking_trips WHERE tenant_id = ? AND trip_number = ? LIMIT 1");
            $tripCheck->execute([$tenant_id, $trip_number]);
            if ($tripCheck->fetch(PDO::FETCH_ASSOC)) {
                $trip_number = $trip_number . '-' . rand(100, 999);
            }

            $stmt = $pdo->prepare("INSERT INTO trucking_trips (tenant_id, container_id, trip_number, status, created_at) VALUES (?, ?, ?, 'received', NOW())");
            $stmt->execute([$tenant_id, $container_id, $trip_number]);
            $trip_id = (int)$pdo->lastInsertId();

            $pdo->commit();
            $msg = $existingContainer
                ? "Kontaynerka '$container_number' horay ayuu u jiray; safar cusub ayaa lagu xiray."
                : "Kontaynerka '$container_number' iyo safarkiisa waa la abuuray.";
            echo json_encode(['success' => true, 'id' => $trip_id, 'name' => "$trip_number ($container_number)", 'message' => $msg]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == 23000) {
                echo json_encode(['success' => false, 'message' => "Kontaynerkan horay ayuu u diiwaangashan yahay. Fadlan dooro safarka jira ama isticmaal lambar kontayner oo cusub."]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
            }
        }
        exit;
    }

    if ($action === 'get_trip_container_info') {
        $trip_id = (int)($_POST['trip_id'] ?? 0);
        
        try {
            $stmt = $pdo->prepare("
                SELECT c.id as container_id, c.container_number, c.fulcode, c.status, c.current_location,
                       c.estimated_arrival as eta, c.arrival_date, c.size_cbm as capacity,
                       c.vessel_name, c.bl_number, c.port_of_loading, c.port_of_discharge,
                       (SELECT SUM(cbm_used) FROM cargo_manifest_items WHERE container_id = c.id AND tenant_id = ?) as used_cbm
                FROM trucking_trips t
                JOIN containers c ON t.container_id = c.id
                WHERE t.id = ? AND t.tenant_id = ?
            ");
            $stmt->execute([$session_tenant_id, $trip_id, $session_tenant_id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                SELECT stock_name, quantity, cbm_used 
                FROM cargo_manifest_items 
                WHERE container_id = (SELECT container_id FROM trucking_trips WHERE id = ? AND tenant_id = ?)
                AND tenant_id = ?
                ORDER BY added_at DESC
            ");
            $stmt->execute([$trip_id, $session_tenant_id, $session_tenant_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'container' => $container, 'items' => $items]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_stock_items') {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_POST['search'] ?? '';
        $origin_filter = $_POST['origin_branch_id'] ?? ($_POST['origin'] ?? 'all');
        $low_stock_only = isset($_POST['low_stock_only']) ? (int)$_POST['low_stock_only'] : 0;
        
        $where_conditions = ["ws.tenant_id = ?"];
        $params = [$session_tenant_id];
        
        if (!empty($search)) {
            $where_conditions[] = "(ws.stock_name LIKE ? OR ws.location LIKE ? OR ws.bin_location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($origin_filter !== 'all' && $origin_filter !== '') {
            $where_conditions[] = "ws.origin_branch_id = ?";
            $params[] = (int)$origin_filter;
        }
        
        if ($low_stock_only == 1) {
            $where_conditions[] = "ws.quantity <= ws.minimum_stock";
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        $count_sql = "SELECT COUNT(*) as total FROM warehouse_stock ws $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_items / $limit);
        
        $sql = "
            SELECT ws.*, 
                   c.customer_name,
                   c.phone,
                   u.full_name as updated_by_name,
                   b.branch_name as origin_branch_name,
                   b.branch_type as origin_branch_type,
                   b.branch_code as origin_branch_code
            FROM warehouse_stock ws
            LEFT JOIN customers c ON ws.customer_id = c.id
            LEFT JOIN users u ON ws.updated_by = u.id
            LEFT JOIN branches b ON ws.origin_branch_id = b.id
            $where_clause
            ORDER BY 
                CASE WHEN ws.quantity <= ws.minimum_stock THEN 1 ELSE 2 END,
                b.branch_name ASC,
                ws.origin ASC,
                ws.stock_name ASC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_start(); ?>
        <div style="overflow-x: auto; width: 100%;">
            <table class="stock-table" style="min-width: 1200px; width: 100%;">
                <thead>
                    <tr>
                        <th>ID</th><th>Magaca Alaabta</th><th>Asal</th><th>Tirada</th><th>Mugga (CBM)</th><th>Bakhaar</th><th>Xaalad</th><th>Qiimaha</th><th>Macaamiil</th><th>Ficillo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($stock_items) > 0): ?>
                        <?php foreach ($stock_items as $item): 
                            $originText = !empty($item['origin_branch_name']) ? $item['origin_branch_name'] : ($item['origin'] === 'china_yiwu' ? 'Shiinaha (Yiwu)' : ($item['origin'] === 'china_guangzhou' ? 'Shiinaha (Guangzhou)' : ($item['origin'] === 'dubai' ? 'Dubaai' : 'Laanta lama dooran')));
                            $originIcon = !empty($item['origin_branch_type']) ? branchIconByType($item['origin_branch_type']) : (strpos((string)$item['origin'], 'china') !== false ? '🇨🇳' : ($item['origin'] === 'dubai' ? '🇦🇪' : '📍'));
                            $originClass = 'branch-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($item['origin_branch_id'] ?? $item['origin'] ?? 'none'));
                            $isLowStock = $item['quantity'] <= $item['minimum_stock'];
                            $stockStatusClass = $isLowStock ? 'status-low' : 'status-good';
                            $stockStatusText = $isLowStock ? 'Tirada yaraatay' : 'Wanaagsan';
                        ?>
                            <tr class="<?= $isLowStock ? 'low-stock-row' : '' ?>">
                                <td><?= $item['id'] ?></td>
                                <td><strong><?= htmlspecialchars($item['stock_name'] ?? '-') ?></strong><br><small>SKU: STK-<?= str_pad($item['id'], 5, '0', STR_PAD_LEFT) ?></small></td>
                                <td><span class="origin-badge <?= htmlspecialchars($originClass) ?>"><?= $originIcon ?> <?= htmlspecialchars($originText) ?></span></td>
                                <td><strong class="<?= $isLowStock ? 'text-danger' : 'text-success' ?>"><?= number_format($item['quantity']) ?></strong></td>
                                <td><?= number_format($item['volume_cbm'], 6) ?> CBM</td>
                                <td><?= htmlspecialchars($item['location'] ?? '-') ?></td>
                                <td><span class="stock-badge <?= $stockStatusClass ?>"><?= $stockStatusText ?></span></td>
                                <td>$<?= number_format($item['unit_price'], 2) ?><br><small>Total: $<?= number_format($item['volume_cbm'] * $item['unit_price'], 2) ?></small></td>
                                <td><?= htmlspecialchars($item['customer_name'] ?? '-') ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn btn-view view-stock" data-id="<?= $item['id'] ?>"><i class="fas fa-eye"></i></button>
                                        <button class="action-btn btn-edit edit-stock" data-id="<?= $item['id'] ?>"><i class="fas fa-edit"></i></button>
                                        <button class="action-btn btn-load load-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" data-qty="<?= $item['quantity'] ?>" data-origin="<?= (int)($item['origin_branch_id'] ?? 0) ?>" data-customer-id="<?= $item['customer_id'] ?>" data-customer-phone="<?= htmlspecialchars($item['phone'] ?? '') ?>"><i class="fas fa-truck-loading"></i></button>
                                        <button class="action-btn btn-move move-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>"><i class="fas fa-exchange-alt"></i></button>
                                        <button class="action-btn btn-adjust adjust-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>" data-qty="<?= $item['quantity'] ?>" data-customer-id="<?= $item['customer_id'] ?>" data-customer-phone="<?= htmlspecialchars($item['phone'] ?? '') ?>"><i class="fas fa-sliders-h"></i></button>
                                        <button class="action-btn btn-track whatsapp-package" data-phone="<?= htmlspecialchars($item['phone'] ?? '') ?>" data-name="<?= htmlspecialchars($item['customer_name'] ?? 'Macaamiil') ?>" data-item="<?= htmlspecialchars($item['stock_name'] ?? 'Alab') ?>" data-qty="<?= $item['quantity'] ?>" data-cbm="<?= number_format($item['volume_cbm'], 4) ?>" data-rate="<?= number_format($item['unit_price'], 2) ?>"><i class="fab fa-whatsapp"></i></button>
                                        <button class="action-btn btn-delete delete-stock" data-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['stock_name']) ?>"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" style="text-align: center; padding: 50px;"><div class="empty-state"><i class="fas fa-warehouse"></i><p>Alaab laguma hayo bakhaarka</p><button class="btn-primary-custom" id="addStockBtnEmpty"><i class="fas fa-plus-circle"></i> Ku dar Alaab</button></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $table_html = ob_get_clean();
        
        ob_start();
        if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a data-page="<?= $page-1 ?>"><i class="fas fa-chevron-left"></i> Hore</a><?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?><span class="active"><?= $i ?></span><?php else: ?><a data-page="<?= $i ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a data-page="<?= $page+1 ?>">Dambe <i class="fas fa-chevron-right"></i></a><?php endif; ?>
            </div>
        <?php endif;
        $pagination_html = ob_get_clean();
        
        echo json_encode(['table_html' => $table_html, 'pagination_html' => $pagination_html]);
        exit;
    }
    
    elseif ($action === 'get_stock_item') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT ws.*, c.customer_name, c.phone, u.full_name as updated_by_name, b.branch_name as origin_branch_name, b.branch_type as origin_branch_type, b.branch_code as origin_branch_code FROM warehouse_stock ws LEFT JOIN customers c ON ws.customer_id = c.id LEFT JOIN users u ON ws.updated_by = u.id LEFT JOIN branches b ON ws.origin_branch_id = b.id WHERE ws.id = ? AND ws.tenant_id = ?");
        $stmt->execute([$id, $session_tenant_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($item);
        exit;
    }
    
    elseif ($action === 'save_stock_item') {
        $id = trim((string)($_POST['stock_id'] ?? ''));
        $tenant_id = (int)$session_tenant_id;
        $user_id_safe = (int)($_SESSION['user_id'] ?? 0);

        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $origin_branch_id = !empty($_POST['origin_branch_id'] ?? $_POST['origin'] ?? '') ? (int)($_POST['origin_branch_id'] ?? $_POST['origin']) : null;
        $origin = 'local';

        $stock_name = trim((string)($_POST['stock_name'] ?? ''));
        $quantity = intPostValue('quantity', 0);
        $location = trim((string)($_POST['location'] ?? ''));
        $minimum_stock = intPostValue('minimum_stock', 0);
        $maximum_stock = intPostValue('maximum_stock', 0);
        $unit_price = decimalPostValue('unit_price', 0);
        $send_whatsapp = isset($_POST['send_whatsapp']) ? (int)$_POST['send_whatsapp'] : 1;

        $length_cm = decimalPostValue('length_cm', 0);
        $width_cm = decimalPostValue('width_cm', 0);
        $height_cm = decimalPostValue('height_cm', 0);
        $volume_cbm = decimalPostValue('volume_cbm', 0);

        if ($stock_name === '') {
            echo json_encode(['success' => false, 'message' => 'Magaca alaabta waa lagama maarmaan']);
            exit;
        }

        if ($quantity < 0) {
            echo json_encode(['success' => false, 'message' => 'Tirada kama yaraan karto 0']);
            exit;
        }

        if (!$origin_branch_id) {
            echo json_encode(['success' => false, 'message' => 'Fadlan dooro laanta/asalka alaabta']);
            exit;
        }

        try {
            $branchCheck = $pdo->prepare("SELECT branch_name, branch_code FROM branches WHERE id = ? AND tenant_id = ? AND (status = 'active' OR is_active = 1) LIMIT 1");
            $branchCheck->execute([$origin_branch_id, $tenant_id]);
            $branchInfo = $branchCheck->fetch(PDO::FETCH_ASSOC);

            if (!$branchInfo) {
                echo json_encode(['success' => false, 'message' => 'Laanta/asalka la doortay lama helin ama ma shaqeynayo']);
                exit;
            }

            $origin = legacyOriginFromBranchName($branchInfo['branch_name'], $branchInfo['branch_code'] ?? '');

            if ($volume_cbm <= 0 && $length_cm > 0 && $width_cm > 0 && $height_cm > 0) {
                $volume_cbm = ($origin === 'dubai')
                    ? (($length_cm * $width_cm * $height_cm) * 0.0283168)
                    : (($length_cm * $width_cm * $height_cm) / 1000000);
            }

            $pdo->beginTransaction();

            if ($id === '') {
                // Duplicate rule:
                // Same tenant + customer + branch/asalka + stock_name + location = one record.
                // If the same item is submitted again, quantity is added to the existing record instead of inserting a duplicate row.
                $duplicateId = findDuplicateStockId($pdo, $tenant_id, $customer_id, $origin_branch_id, $stock_name, $location);

                if ($duplicateId) {
                    updateExistingStockInsteadOfDuplicate(
                        $pdo,
                        $duplicateId,
                        $tenant_id,
                        $customer_id,
                        $origin_branch_id,
                        $origin,
                        $stock_name,
                        $quantity,
                        $length_cm,
                        $width_cm,
                        $height_cm,
                        $volume_cbm,
                        $location,
                        $minimum_stock,
                        $maximum_stock,
                        $unit_price,
                        $user_id_safe
                    );

                    $pdo->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => "Duplicate lama gelin. Alaabta '$stock_name' record-keedii hore ayaa tirada loogu daray.",
                        'updated_existing' => true,
                        'stock_id' => $duplicateId
                    ]);
                    exit;
                }

                $new_id = insertStockSafely(
                    $pdo,
                    $tenant_id,
                    $customer_id,
                    $origin_branch_id,
                    $origin,
                    $stock_name,
                    $quantity,
                    $length_cm,
                    $width_cm,
                    $height_cm,
                    $volume_cbm,
                    $location,
                    $minimum_stock,
                    $maximum_stock,
                    $unit_price,
                    $user_id_safe
                );

                $pdo->commit();

                $whatsapp_result = null;
                if ($send_whatsapp === 1 && $customer_id && $quantity > 0) {
                    $whatsapp_result = sendAutoWhatsAppGreenAPI($pdo, $customer_id, $stock_name, $quantity, 'added', $tenant_id, '', null, ($volume_cbm * $quantity), $unit_price);
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Alaabta '$stock_name' waa la kaydiyay!",
                    'stock_id' => $new_id,
                    'whatsapp' => $whatsapp_result
                ]);
            } else {
                $stock_id = (int)$id;

                $check = $pdo->prepare("SELECT id FROM warehouse_stock WHERE id = ? AND tenant_id = ? LIMIT 1");
                $check->execute([$stock_id, $tenant_id]);
                if (!$check->fetch(PDO::FETCH_ASSOC)) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                    exit;
                }

                $duplicateId = findDuplicateStockId($pdo, $tenant_id, $customer_id, $origin_branch_id, $stock_name, $location, $stock_id);
                if ($duplicateId) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => "Alaabtan hore ayay u jirtaa. Bedel record-ka hore ama dooro magac/location kale."
                    ]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    UPDATE warehouse_stock
                    SET customer_id = :customer_id,
                        origin_branch_id = :origin_branch_id,
                        origin = :origin,
                        stock_name = :stock_name,
                        quantity = :quantity,
                        length_cm = :length_cm,
                        width_cm = :width_cm,
                        height_cm = :height_cm,
                        volume_cbm = :volume_cbm,
                        location = :location,
                        minimum_stock = :minimum_stock,
                        maximum_stock = :maximum_stock,
                        unit_price = :unit_price,
                        updated_by = :updated_by,
                        last_updated = NOW()
                    WHERE id = :id
                      AND tenant_id = :tenant_id
                ");
                $stmt->execute([
                    ':customer_id' => $customer_id,
                    ':origin_branch_id' => $origin_branch_id,
                    ':origin' => $origin,
                    ':stock_name' => $stock_name,
                    ':quantity' => $quantity,
                    ':length_cm' => $length_cm,
                    ':width_cm' => $width_cm,
                    ':height_cm' => $height_cm,
                    ':volume_cbm' => $volume_cbm,
                    ':location' => $location,
                    ':minimum_stock' => $minimum_stock,
                    ':maximum_stock' => $maximum_stock,
                    ':unit_price' => $unit_price,
                    ':updated_by' => $user_id_safe,
                    ':id' => $stock_id,
                    ':tenant_id' => $tenant_id
                ]);

                $pdo->commit();

                echo json_encode(['success' => true, 'message' => "Alaabta '$stock_name' waa la cusboonaysiiyay!"]);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'delete_stock_item') {
        $id = $_POST['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT stock_name FROM warehouse_stock WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM warehouse_stock WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' waa la tirtiray!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'move_stock') {
        $id = $_POST['id'] ?? 0;
        $new_location = trim($_POST['new_location'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($new_location)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli bakhaarka cusub']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT stock_name, location FROM warehouse_stock WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $session_tenant_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }
            
            $old_location = $item['location'];
            $sql = "UPDATE warehouse_stock SET location = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND tenant_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_location, $_SESSION['user_id'], $id, $session_tenant_id]);
            
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' waxaa laga raray '$old_location' loo raray '$new_location'!"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'adjust_stock') {
        $id = $_POST['id'] ?? 0;
        $adjustment = (int)$_POST['adjustment'] ?? 0;
        $reason = trim($_POST['reason'] ?? '');
        $send_whatsapp = isset($_POST['send_whatsapp']) ? (int)$_POST['send_whatsapp'] : 1;
        
        if ($adjustment == 0) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli tirada beddelka']);
            exit;
        }
        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Fadlan sabab u qor beddelka']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT stock_name, quantity, customer_id FROM warehouse_stock WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $stmt->execute([$id, $session_tenant_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }
            
            $new_quantity = $item['quantity'] + $adjustment;
            if ($new_quantity < 0) {
                echo json_encode(['success' => false, 'message' => 'Tirada ma noqon karto mid ka yar eber']);
                exit;
            }
            
            $sql = "UPDATE warehouse_stock SET quantity = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND tenant_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_quantity, $_SESSION['user_id'], $id, $session_tenant_id]);
            
            $whatsapp_result = null;
            if ($send_whatsapp == 1 && $adjustment < 0 && $item['customer_id']) {
                $removed_qty = abs($adjustment);
                $whatsapp_result = sendAutoWhatsAppGreenAPI($pdo, $item['customer_id'], $item['stock_name'], $removed_qty, 'removed', $session_tenant_id, $reason);
            }
            
            $pdo->commit();
            $action_text = $adjustment > 0 ? "ku dartay $adjustment" : "kaga saaray " . abs($adjustment);
            echo json_encode(['success' => true, 'message' => "Alaabta '{$item['stock_name']}' waxaan $action_text! Tirada cusub: $new_quantity", 'whatsapp' => $whatsapp_result]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_available_trips') {
        $origin_branch_id = !empty($_POST['origin_branch_id'] ?? $_POST['origin'] ?? '')
            ? (int)($_POST['origin_branch_id'] ?? $_POST['origin'])
            : 0;

        if ($origin_branch_id <= 0) {
            echo json_encode([]);
            exit;
        }

        try {
            $sql = "
                SELECT 
                    tt.id,
                    tt.trip_number,
                    c.id AS container_id,
                    c.container_number,
                    COALESCE(c.tracking_number, c.container_number) AS fulcode,
                    c.container_type,
                    c.size_cbm,
                    c.status,
                    c.current_location,
                    c.estimated_arrival,
                    c.vessel_name,
                    c.origin_branch_id,
                    b.branch_name AS origin_branch_name,
                    COALESCE(SUM(cmi.cbm_used), 0) AS used_cbm,
                    (COALESCE(c.size_cbm, 0) - COALESCE(SUM(cmi.cbm_used), 0)) AS remaining_cbm
                FROM trucking_trips tt
                INNER JOIN containers c
                    ON tt.container_id = c.id
                   AND c.tenant_id = tt.tenant_id
                LEFT JOIN cargo_manifest_items cmi
                    ON cmi.container_id = c.id
                   AND cmi.tenant_id = tt.tenant_id
                LEFT JOIN branches b
                    ON c.origin_branch_id = b.id
                   AND b.tenant_id = c.tenant_id
                WHERE tt.tenant_id = ?
                  AND c.tenant_id = ?
                  AND c.origin_branch_id = ?
                  AND c.status IN ('received', 'loading')
                GROUP BY tt.id, c.id
                HAVING remaining_cbm > 0 OR c.size_cbm IS NULL OR c.size_cbm = 0
                ORDER BY tt.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$session_tenant_id, $session_tenant_id, $origin_branch_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('get_available_trips failed: ' . $e->getMessage());
            echo json_encode([]);
        }
        exit;
    }
    
    elseif ($action === 'load_to_container') {
        $stock_id = (int)($_POST['stock_id'] ?? 0);
        $trip_id = (int)($_POST['trip_id'] ?? 0);
        $qty_to_load = (int)($_POST['quantity'] ?? 0);
        $send_whatsapp = isset($_POST['send_whatsapp']) ? (int)$_POST['send_whatsapp'] : 1;

        if ($stock_id <= 0 || $trip_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Alaabta ama safarka lama dooran']);
            exit;
        }
        
        if ($qty_to_load <= 0) {
            echo json_encode(['success' => false, 'message' => 'Fadlan geli tirada la rarayo']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get stock item + branch info, lock row to avoid double loading.
            $stmt = $pdo->prepare("
                SELECT 
                    ws.*,
                    c.phone AS customer_phone,
                    c.customer_name,
                    b.branch_name AS stock_branch_name
                FROM warehouse_stock ws
                LEFT JOIN customers c ON ws.customer_id = c.id AND c.tenant_id = ws.tenant_id
                LEFT JOIN branches b ON ws.origin_branch_id = b.id AND b.tenant_id = ws.tenant_id
                WHERE ws.id = ?
                  AND ws.tenant_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$stock_id, $session_tenant_id]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$stock) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Alaabta lama helin']);
                exit;
            }

            if ((int)$stock['quantity'] < $qty_to_load) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => "Tirada la rarayo way ka badan tahay tirada kaydka (Kaydka: {$stock['quantity']})"]);
                exit;
            }

            if (empty($stock['origin_branch_id'])) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Alaabtan laan/asal kuma xirna. Marka hore edit garee oo dooro laanta/asalka alaabta.']);
                exit;
            }
            
            // Get container details + branch info.
            $stmt = $pdo->prepare("
                SELECT 
                    c.id AS container_id,
                    c.container_number,
                    COALESCE(c.tracking_number, c.container_number) AS fulcode,
                    c.status,
                    c.current_location,
                    c.estimated_arrival,
                    c.vessel_name,
                    c.port_of_discharge,
                    c.origin_branch_id,
                    b.branch_name AS container_branch_name
                FROM trucking_trips t
                INNER JOIN containers c ON t.container_id = c.id
                LEFT JOIN branches b ON c.origin_branch_id = b.id AND b.tenant_id = c.tenant_id
                WHERE t.id = ?
                  AND t.tenant_id = ?
                  AND c.tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$trip_id, $session_tenant_id, $session_tenant_id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$container) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Safarka/kontaynerka la xushay lama helin']);
                exit;
            }

            // Hard backend validation: alaabta iyo container-ka waa inay isku origin_branch_id noqdaan.
            if ((int)($container['origin_branch_id'] ?? 0) !== (int)($stock['origin_branch_id'] ?? 0)) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'Lama rari karo: alaabta laanteeda waa "' .
                        ($stock['stock_branch_name'] ?? 'lama yaqaan') .
                        '", kontaynerkana waa "' .
                        ($container['container_branch_name'] ?? 'lama yaqaan') .
                        '". Waa inay isku laan noqdaan.'
                ]);
                exit;
            }

            // Hubi xaaladda kontaynarka ka hor inta aan alaab lagu darin.
            $blocked_container_statuses = ['shipped', 'dispatched', 'at_port', 'ready', 'delivered'];
            if (in_array($container['status'], $blocked_container_statuses, true)) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => "Kontaynarkan lama rari karo sababtoo ah xaaladdiisu waa " . somaliContainerStatusText($container['status']) . "."
                ]);
                exit;
            }
            
            // Update stock quantity.
            $new_qty = (int)$stock['quantity'] - $qty_to_load;
            $stmt = $pdo->prepare("UPDATE warehouse_stock SET quantity = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$new_qty, $_SESSION['user_id'], $stock_id, $session_tenant_id]);
            
            $loaded_cbm = (float)$stock['volume_cbm'] * $qty_to_load;
            $loaded_weight = (float)($stock['weight_kg'] ?? 0) * $qty_to_load;
            $unit_price = (float)($stock['unit_price'] ?? 0);

            // Add to cargo manifest without duplicate rows for same stock in same container.
            $manifestCheck = $pdo->prepare("
                SELECT id
                FROM cargo_manifest_items
                WHERE tenant_id = ?
                  AND container_id = ?
                  AND warehouse_stock_id = ?
                LIMIT 1
            ");
            $manifestCheck->execute([$session_tenant_id, $container['container_id'], $stock_id]);
            $existingManifest = $manifestCheck->fetch(PDO::FETCH_ASSOC);

            if ($existingManifest) {
                $stmt = $pdo->prepare("
                    UPDATE cargo_manifest_items
                    SET quantity = COALESCE(quantity, 0) + ?,
                        cbm_used = COALESCE(cbm_used, 0) + ?,
                        weight_kg = COALESCE(weight_kg, 0) + ?,
                        unit_price = ?,
                        stock_name = ?,
                        added_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                ");
                $stmt->execute([
                    $qty_to_load,
                    $loaded_cbm,
                    $loaded_weight,
                    $unit_price,
                    $stock['stock_name'],
                    $existingManifest['id'],
                    $session_tenant_id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO cargo_manifest_items
                        (tenant_id, container_id, warehouse_stock_id, quantity, cbm_used, weight_kg, unit_price, stock_name, added_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $session_tenant_id,
                    $container['container_id'],
                    $stock_id,
                    $qty_to_load,
                    $loaded_cbm,
                    $loaded_weight,
                    $unit_price,
                    $stock['stock_name']
                ]);
            }
            
            // Update container used CBM + status.
            $stmt = $pdo->prepare("
                UPDATE containers
                SET size_used_cbm = COALESCE((
                        SELECT SUM(cbm_used)
                        FROM cargo_manifest_items
                        WHERE container_id = ?
                          AND tenant_id = ?
                    ), 0),
                    status = CASE WHEN status = 'received' THEN 'loading' ELSE status END,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
            ");
            $stmt->execute([$container['container_id'], $session_tenant_id, $container['container_id'], $session_tenant_id]);

            // Update trip status.
            $stmt = $pdo->prepare("
                UPDATE trucking_trips
                SET status = CASE WHEN status = 'received' THEN 'loading' ELSE status END
                WHERE id = ?
                  AND tenant_id = ?
            ");
            $stmt->execute([$trip_id, $session_tenant_id]);
            
            $whatsapp_result = null;
            if ($send_whatsapp == 1 && !empty($stock['customer_id'])) {
                $whatsapp_result = sendAutoWhatsAppGreenAPI(
                    $pdo,
                    $stock['customer_id'],
                    $stock['stock_name'],
                    $qty_to_load,
                    'loaded',
                    $session_tenant_id,
                    '',
                    $container['container_id'],
                    $loaded_cbm,
                    $unit_price
                );
            }
            
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "Alaabta '{$stock['stock_name']}' si guul leh ayaa loogu raray kontaynerka {$container['container_number']}.",
                'whatsapp' => $whatsapp_result,
                'container' => $container,
                'new_quantity' => $new_qty
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Khalad: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_stats') {
        $where = "WHERE tenant_id = $session_tenant_id";
        $stmt = $pdo->query("SELECT COUNT(*) as total_items, SUM(quantity) as total_quantity, SUM(volume_cbm) as total_volume, SUM(volume_cbm * unit_price) as total_value, COUNT(CASE WHEN quantity <= minimum_stock THEN 1 END) as low_stock_items FROM warehouse_stock $where");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['stats' => $stats]);
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
    <title>Maareynta Bakhaarka - <?= htmlspecialchars($tenant_name) ?> | <?= htmlspecialchars($tenant_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --curdun-violet: #2D1859;
            --curdun-yellow: #F5C410;
            --curdun-violet-light: #4B2C85;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .page-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); border-radius: 16px; padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .page-header h1 { color: white; font-size: 24px; margin: 0; }
        .page-header h1 i { margin-right: 10px; }
        .btn-primary-custom { background: var(--curdun-yellow); color: var(--curdun-violet); border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
        .stat-card .stat-info h4 { font-size: 11px; color: #6c757d; margin: 0; }
        .stat-card .stat-info .stat-number { font-size: 22px; font-weight: 700; color: var(--curdun-violet); }
        .filters-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 25px; }
        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; }
        .btn-filter { background: var(--curdun-violet); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer; }
        .stock-table-container { background: white; border-radius: 12px; overflow-x: auto; }
        .stock-table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        .stock-table th, .stock-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        .stock-table th { background: #f8f6f9; font-weight: 600; }
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-btn { padding: 5px 8px; border-radius: 6px; border: none; cursor: pointer; }
        .btn-view { background: #e8eaf6; color: #3949ab; }
        .btn-edit { background: #fff3e0; color: #e65100; }
        .btn-load { background: #e0f2f1; color: #00695c; }
        .btn-move { background: #e3f2fd; color: #1565c0; }
        .btn-adjust { background: #fff8e1; color: #ff8f00; }
        .btn-track { background: #dcf8c5; color: #25D366; }
        .btn-delete { background: #FEF0EE; color: #B42318; }
        .origin-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; }
        .origin-china_yiwu, .origin-china_guangzhou { background: #e3f2fd; color: #1565c0; }
        .origin-dubai { background: #fff3e0; color: #e65100; }
        .origin-badge[class*="branch-"] { background: #EEFBF3; color: #0F7A3A; }
        .stock-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; }
        .status-good { background: #EEFBF3; color: #0F7A3A; }
        .status-low { background: #FEF0EE; color: #B42318; }
        .low-stock-row { background: rgba(198,40,40,0.05); }
        .alert { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: white; }
        .modal-header .close { color: white; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; background: white; border: 1px solid #ddd; cursor: pointer; }
        .pagination .active { background: var(--curdun-violet); color: white; }
        @media (max-width: 768px) { .filter-form { flex-direction: column; } .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        
        /* Container info panel */
        .container-info-panel { background: #EEFBF3; border-left: 4px solid #0F7A3A; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; }
        .container-info-panel code { background: #fff; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
        .tracking-link { color: #1565c0; text-decoration: underline; cursor: pointer; }

        .customer-search-box { position: relative; }
        .customer-search-results {
            display: none;
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            z-index: 1060;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 260px;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
        }
        .customer-result-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        .customer-result-item:hover { background: #f7f5ff; }
        .customer-result-name { font-weight: 700; color: #222; }
        .customer-result-meta { font-size: 12px; color: #666; margin-top: 2px; }
        .customer-result-empty { padding: 12px; color: #666; }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px;">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-warehouse"></i> Maareynta Bakhaarka</h1>
        <div class="d-flex flex-wrap align-items-center" style="gap:8px;">
            <span class="company-badge" style="background:rgba(255,255,255,0.2);padding:8px 16px;border-radius:20px;"><i class="fas fa-building"></i> <?= htmlspecialchars($tenant_name) ?></span>
            <button class="btn-primary-custom" id="addStockBtn"><i class="fas fa-plus-circle"></i> Ku Dar Alab</button>
            <a href="?action=export_stock" id="exportStockBtn" class="btn btn-light" style="border-radius:8px;font-weight:600;"><i class="fas fa-file-export"></i> Export</a>
            <button type="button" class="btn btn-success" id="importStockBtn" style="border-radius:8px;font-weight:600;"><i class="fas fa-file-import"></i> Import</button>
            <a href="?action=download_stock_import_template" class="btn btn-info" style="border-radius:8px;font-weight:600;"><i class="fas fa-download"></i> Template</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-info"><h4>Tirada Alaabta</h4><div class="stat-number" id="stat-total-items">0</div></div><div class="stat-icon"><i class="fas fa-boxes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Tirada Guud</h4><div class="stat-number" id="stat-total-quantity">0</div></div><div class="stat-icon"><i class="fas fa-cubes"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Mugga Guud (CBM)</h4><div class="stat-number" id="stat-total-volume">0</div></div><div class="stat-icon"><i class="fas fa-cube"></i></div></div>
        <div class="stat-card"><div class="stat-info"><h4>Qiimaha Guud</h4><div class="stat-number" id="stat-total-value">$0</div></div><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div></div>
        <div class="stat-card stat-card-danger"><div class="stat-info"><h4>Tirada Daciifsan</h4><div class="stat-number" id="stat-low-stock">0</div></div><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div></div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label>Raadin</label><input type="text" id="searchInput" placeholder="Magaca alabta, bakhaarka..."></div>
            <div class="filter-group"><label>Asal / Laanta</label><select id="originFilter"><option value="all">Dhammaan Laamaha</option><?php foreach ($origin_branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= branchIconByType($b['branch_type'] ?? '') ?> <?= htmlspecialchars($b['branch_name']) ?><?= !empty($b['branch_code']) ? ' (' . htmlspecialchars($b['branch_code']) . ')' : '' ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label><input type="checkbox" id="lowStockOnly"> Tirada Daciifsan</label></div>
            <div class="filter-group"><button class="btn-filter" id="applyFilters">Shaandhayn</button><button class="btn-reset" id="resetFilters" style="background:#f0f0f0;border:none;padding:8px 20px;border-radius:8px;margin-left:10px;">Damee</button></div>
        </div>
    </div>

    <div id="stock-table-container"><div class="loading-spinner" style="text-align:center;padding:50px;"><i class="fas fa-spinner fa-spin"></i><p>Alaabta waa la soo rarayaa...</p></div></div>
    <div id="pagination-container"></div>
</div>

<!-- Modal Ku Dar / Wax Ka Beddel Alaabta -->
<div class="modal fade" id="stockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="stockModalLabel"><i class="fas fa-box"></i> Ku Dar Alab</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="stockForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="stock_id">
                    <div class="row">
                        <div class="col-md-6"><div class="form-group customer-search-box"><label>Macaamiil <button type="button" id="quickAddCustomerBtn" class="btn btn-sm btn-primary">+</button></label><input type="hidden" name="customer_id" id="modalCustomerId"><input type="text" id="modalCustomerSearch" class="form-control" autocomplete="off" placeholder="Raadi magaca ama telefoonka macaamiilka..."><div id="modalCustomerResults" class="customer-search-results"></div><small id="modalCustomerInfo" class="text-muted d-block mt-1">Customer lama dooran.</small></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Asalka / Laanta</label><select name="origin_branch_id" id="modalOrigin" class="form-control" required><option value="">Dooro laanta/asalka...</option><?php foreach ($origin_branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= branchIconByType($b['branch_type'] ?? '') ?> <?= htmlspecialchars($b['branch_name']) ?><?= !empty($b['branch_code']) ? ' (' . htmlspecialchars($b['branch_code']) . ')' : '' ?></option><?php endforeach; ?></select><small class="text-muted">Liiskan wuxuu ka imaanayaa branches-ka diiwaangashan.</small></div></div>
                        <div class="col-md-12"><div class="form-group"><label>Magaca Alaabta</label><input type="text" name="stock_name" id="modalStockName" class="form-control" placeholder="Tusaale: Dharka, Kabaha, Shandadaha..."></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Tirada</label><input type="number" name="quantity" id="modalQuantity" class="form-control" value="1" min="1" required></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Qiimaha Halbeeg ($)</label><input type="number" step="0.01" name="unit_price" id="modalUnitPrice" class="form-control" value="0"></div></div>
                        <div class="col-md-12">
                            <div style="background:#f8f9fa; padding:10px; border-radius:8px; margin-bottom:15px;">
                                <label><i class="fas fa-ruler-combined"></i> Cabirrada Alaabta</label>
                                <div class="row">
                                    <div class="col-md-4"><label style="font-size:11px;">Dherer (cm/ft)</label><input type="number" step="0.1" name="length_cm" id="modalLength" class="form-control dimension-input" value="0"></div>
                                    <div class="col-md-4"><label style="font-size:11px;">Ballac (cm/ft)</label><input type="number" step="0.1" name="width_cm" id="modalWidth" class="form-control dimension-input" value="0"></div>
                                    <div class="col-md-4"><label style="font-size:11px;">Sare (cm/ft)</label><input type="number" step="0.1" name="height_cm" id="modalHeight" class="form-control dimension-input" value="0"></div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-6"><label>Mugga (CBM)</label><input type="number" step="0.000001" name="volume_cbm" id="modalVolume" class="form-control" value="0" placeholder="Otomatik ama gacanta"></div>
                                    <div class="col-md-6"><label>Bakhaarka</label><input type="text" name="location" id="modalLocation" class="form-control" placeholder="Qaybta bakhaarka"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6"><div class="form-group"><label>Ugu Yar (Digniin)</label><input type="number" name="minimum_stock" id="modalMinStock" class="form-control" value="0"></div></div>
                        <div class="col-md-6"><div class="form-group"><label>Ugu Badan</label><input type="number" name="maximum_stock" id="modalMaxStock" class="form-control" value="0"></div></div>
                    </div>
                    <div class="alert alert-info mt-2"><i class="fas fa-calculator"></i> <strong>Qiimaha Guud:</strong> $<span id="totalValuePreview">0.00</span> (Mugga × Qiimaha Halbeeg)</div>
                    <div class="form-group"><label><input type="checkbox" id="saveSendWhatsapp" checked> <i class="fab fa-whatsapp text-success"></i> U dir Macaamiilka farriin WhatsApp otomatik ah</label></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-primary-custom">Kaydi Alaabta</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Rarista Alaabta -->
<div class="modal fade" id="loadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-truck-loading"></i> Alaabta ku Rar Kontayner</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="loadForm">
                <div class="modal-body">
                    <input type="hidden" name="stock_id" id="loadStockId">
                    <p>Alaabta: <strong id="loadStockName"></strong></p>
                    <p>Tirada Kaydka: <strong id="loadStockQty">0</strong></p>
                    <div class="form-group"><label>Dooro Safarka <button type="button" id="quickAddTripBtn" class="btn btn-sm btn-success">+</button></label><select name="trip_id" id="loadTripId" class="form-control" required><option value="">Safarada waa la soo rarayaa...</option></select></div>
                    <div class="form-group"><label>Tirada la Rarayo</label><input type="number" name="quantity" id="loadQuantity" class="form-control" required min="1"></div>
                    <div class="form-group"><label><input type="checkbox" id="loadSendWhatsapp" checked> <i class="fab fa-whatsapp text-success"></i> U dir Macaamiilka farriin WhatsApp otomatik ah</label></div>
                    
                    <!-- Container Info Panel -->
                    <div id="containerInfoPanel" style="display: none;" class="container-info-panel">
                        <strong><i class="fas fa-ship"></i> Macluumaadka Kontaynerka:</strong>
                        <div id="containerInfoDetails"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-primary">Rar</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Dhaqaajinta Alaabta -->
<div class="modal fade" id="moveModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-exchange-alt"></i> U Dhaqaaji Bakhaar Cusub</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <form id="moveForm"><div class="modal-body"><input type="hidden" name="stock_id" id="moveStockId"><p>Alaabta: <strong id="moveStockName"></strong></p><div class="form-group"><label>Bakhaarka Cusub</label><input type="text" name="new_location" id="moveLocation" class="form-control" required></div><div class="form-group"><label>Qoraal (Ikhtiyaar)</label><textarea name="notes" id="moveNotes" class="form-control" rows="2"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-info">Dhaqaaji</button></div></form></div></div></div>

<!-- Modal Beddelka Tirada -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-sliders-h"></i> Beddel Tirada</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <form id="adjustForm"><div class="modal-body"><input type="hidden" name="stock_id" id="adjustStockId"><p>Alaabta: <strong id="adjustStockName"></strong></p><p>Tirada Hadda: <strong id="adjustCurrentQty">0</strong></p><div class="form-group"><label>Beddel (+ ama -)</label><input type="number" name="adjustment" id="adjustmentQty" class="form-control" placeholder="Tusaale: +10 ama -5" required></div><div class="form-group"><label>Sababta</label><textarea name="reason" id="adjustReason" class="form-control" rows="2" required></textarea></div><div class="form-group"><label><input type="checkbox" id="adjustSendWhatsapp" checked> <i class="fab fa-whatsapp text-success"></i> U dir Macaamiilka farriin WhatsApp (haddii la saarayo)</label></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-warning">Beddel</button></div></form></div></div></div>

<!-- Modal Muuqaalka Alaabta -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-box"></i> Faahfaahinta Alaabta</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body" id="viewModalBody"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Xir</button></div></div></div></div>

<!-- Modal Tirtirida -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-danger"><h5 class="modal-title">Tirtir Alaabta</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body">Ma hubtaa inaad tirtirto <strong id="deleteStockName"></strong>?<br><span class="text-danger">Fal saameyn weyn leh!</span></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Tirtir</button></div></div></div></div>


<!-- Modal Import Stock CSV -->
<div class="modal fade" id="importStockModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Alaabta Bakhaarka</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="importStockForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Fiiro gaar ah:</strong> CSV-ga waa inuu leeyahay columns-kan: 
                        <code>customer_phone</code>, <code>customer_name</code>, <code>origin_branch_id</code> ama <code>origin_branch_name</code>/<code>origin_branch_code</code>, 
                        <code>stock_name</code>, <code>quantity</code>, <code>length_cm</code>, <code>width_cm</code>, <code>height_cm</code>, <code>volume_cbm</code>, <code>location</code>, <code>minimum_stock</code>, <code>maximum_stock</code>, <code>unit_price</code>.
                    </div>
                    <div class="form-group">
                        <label>Dooro CSV File</label>
                        <input type="file" name="import_file" id="importStockFile" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <div id="importResultBox" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <a href="?action=download_stock_import_template" class="btn btn-info"><i class="fas fa-download"></i> Download Template</a>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Import Garee</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Modal Macaamiil Cusub -->
<div class="modal fade" id="quickCustomerModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Ku Dar Macaamiil Cusub</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><form id="quickCustomerForm"><div class="modal-body"><div class="form-group"><label>Magaca Macaamiilka</label><input type="text" name="customer_name" id="qcName" class="form-control" required></div><div class="form-group"><label>Telefoonka</label><input type="text" name="phone" id="qcPhone" class="form-control" required placeholder="+252..."></div><div class="form-group"><label>Email</label><input type="email" name="email" id="qcEmail" class="form-control"></div><div class="form-group"><label>Cinwaanka</label><input type="text" name="address" id="qcAddress" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-primary-custom">Kaydi</button></div></form></div></div></div>

<!-- Modal Safar Cusub -->
<div class="modal fade" id="quickTripModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-success"><h5 class="modal-title">Ku Dar Safar Cusub</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><form id="quickTripForm"><div class="modal-body"><div class="form-group"><label>Lambarka Kontaynerka</label><input type="text" name="container_number" id="qtContainerNumber" class="form-control" required></div><div class="form-group"><label>Asalka / Laanta</label><select name="origin" id="qtOrigin" class="form-control" required><option value="">Dooro laanta...</option><?php foreach ($origin_branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= branchIconByType($b['branch_type'] ?? '') ?> <?= htmlspecialchars($b['branch_name']) ?><?= !empty($b['branch_code']) ? ' (' . htmlspecialchars($b['branch_code']) . ')' : '' ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Nooca Kontaynerka</label><select name="container_type" id="qtContainerType" class="form-control"><option value="20ft">20ft</option><option value="40ft">40ft</option></select></div><div class="form-group"><label>Lambarka Safarka</label><input type="text" name="trip_number" id="qtTripNumber" class="form-control" placeholder="Haddii la dhaafo, si otomatik ah ayaa loo samaynayaa"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button><button type="submit" class="btn btn-success">Kaydi</button></div></form></div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    const companyName = <?= json_encode($tenant_name, JSON_UNESCAPED_UNICODE) ?>;
    let deleteId = null;
    let moveId = null;
    let adjustId = null;


    // ==============================================
    // CUSTOMER SEARCH INPUT - NO SELECT
    // ==============================================
    let customerSearchTimer = null;

    function moneyText(value) {
        const n = parseFloat(value || 0);
        return '$' + n.toFixed(2);
    }

    function selectCustomerForStock(customer) {
        const id = customer && customer.id ? customer.id : '';
        const name = customer && customer.customer_name ? customer.customer_name : '';
        const phone = customer && customer.phone ? customer.phone : '';
        const balance = customer && customer.balance ? customer.balance : 0;
        const points = customer && customer.loyalty_points ? customer.loyalty_points : 0;

        $('#modalCustomerId').val(id);
        $('#modalCustomerSearch').val(id ? `${name} (${phone || 'No phone'})` : '');
        $('#modalCustomerInfo').text(id ? `Phone: ${phone || '-'} | Balance: ${moneyText(balance)} | Points: ${points || 0}` : 'Customer lama dooran.');
        $('#modalCustomerResults').hide().empty();
    }

    function renderCustomerResults(customers, query) {
        const box = $('#modalCustomerResults');
        box.empty();

        if (!customers || customers.length === 0) {
            box.append(`
                <div class="customer-result-empty">
                    Macaamiil lama helin.
                    <button type="button" class="btn btn-sm btn-primary ml-2" id="openQuickAddFromSearch">Add Customer</button>
                </div>
            `);
            box.show();
            return;
        }

        customers.forEach(c => {
            box.append(`
                <div class="customer-result-item"
                     data-id="${escapeHtml(c.id)}"
                     data-name="${escapeHtml(c.customer_name || '')}"
                     data-phone="${escapeHtml(c.phone || '')}"
                     data-balance="${escapeHtml(c.balance || 0)}"
                     data-points="${escapeHtml(c.loyalty_points || 0)}">
                    <div class="customer-result-name">${escapeHtml(c.customer_name || '-')}</div>
                    <div class="customer-result-meta">${escapeHtml(c.phone || '-')} | Balance: ${moneyText(c.balance)} | Points: ${escapeHtml(c.loyalty_points || 0)}</div>
                </div>
            `);
        });

        box.show();
    }

    $('#modalCustomerSearch').on('input', function() {
        const q = $(this).val().trim();
        $('#modalCustomerId').val('');
        $('#modalCustomerInfo').text('Customer lama dooran.');

        clearTimeout(customerSearchTimer);
        if (q.length < 2) {
            $('#modalCustomerResults').hide().empty();
            return;
        }

        customerSearchTimer = setTimeout(function() {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                dataType: 'json',
                data: { ajax_action: 'search_customers', q: q },
                success: function(res) {
                    renderCustomerResults(res.customers || [], q);
                },
                error: function() {
                    $('#modalCustomerResults').html('<div class="customer-result-empty">Search khalad ayuu galay.</div>').show();
                }
            });
        }, 250);
    });

    $(document).on('click', '.customer-result-item', function() {
        selectCustomerForStock({
            id: $(this).data('id'),
            customer_name: $(this).data('name'),
            phone: $(this).data('phone'),
            balance: $(this).data('balance'),
            loyalty_points: $(this).data('points')
        });
    });

    $(document).on('click', '#openQuickAddFromSearch', function() {
        const q = $('#modalCustomerSearch').val().trim();
        $('#qcName').val(q);
        $('#quickCustomerModal').modal('show');
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.customer-search-box').length) {
            $('#modalCustomerResults').hide();
        }
    });

    // ==============================================
    // CBM CALCULATION - SAX AH
    // ==============================================
    function calculateCBM() {
        const origin = $('#modalOrigin').val();
        let l = parseFloat($('#modalLength').val()) || 0;
        let w = parseFloat($('#modalWidth').val()) || 0;
        let h = parseFloat($('#modalHeight').val()) || 0;

        if (l > 0 && w > 0 && h > 0) {
            let volume = 0;
            if (origin === 'dubai') {
                volume = (l * w * h) * 0.0283168;
            } else {
                volume = (l * w * h) / 1000000;
            }
            $('#modalVolume').val(volume.toFixed(6));
        }
        updateTotalPreview();
    }

    function updateTotalPreview() {
        const volume = parseFloat($('#modalVolume').val()) || 0;
        const unitPrice = parseFloat($('#modalUnitPrice').val()) || 0;
        const quantity = parseFloat($('#modalQuantity').val()) || 1;
        const total = (volume * unitPrice) * quantity;
        $('#totalValuePreview').text(total.toFixed(2));
    }

    $('.dimension-input').on('input', calculateCBM);
    $('#modalVolume, #modalUnitPrice, #modalQuantity').on('input', updateTotalPreview);

    $('#modalOrigin').on('change', function() {
        calculateCBM();
    });

    // Load container info when trip is selected
    $('#loadTripId').on('change', function() {
        const tripId = $(this).val();
        if (tripId) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { ajax_action: 'get_trip_container_info', trip_id: tripId },
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.container) {
                        const c = res.container;
                        let statusText = '';
                        switch(c.status) {
                            case 'received': statusText = 'La helay'; break;
                            case 'loading': statusText = 'Waa la rarayaa'; break;
                            case 'loaded': statusText = 'Waa la raray'; break;
                            case 'shipped': statusText = 'Safarka ayuu galay'; break;
                            case 'at_port': statusText = 'Wuxuu jooga dekedda'; break;
                            case 'ready': statusText = 'Waa diyaar'; break;
                            case 'delivered': statusText = 'Waa la gaarsiiyay'; break;
                            default: statusText = c.status;
                        }
                        
                        let etaHtml = '';
                        if (c.eta && c.eta != '0000-00-00') {
                            etaHtml = `<div><strong>⏰ Taariikhda La Filayo:</strong> ${formatDate(c.eta)}</div>`;
                        }
                        
                        let vesselHtml = '';
                        if (c.vessel_name) {
                            vesselHtml = `<div><strong>⚓ Markabka:</strong> ${escapeHtml(c.vessel_name)}</div>`;
                        }
                        
                        let locationHtml = '';
                        if (c.current_location) {
                            locationHtml = `<div><strong>📍 Halka uu joogo:</strong> ${escapeHtml(c.current_location)}</div>`;
                        }
                        
                        $('#containerInfoDetails').html(`
                            <div><strong>🚢 Lambarka:</strong> <code>${escapeHtml(c.container_number)}</code></div>
                            <div><strong>📅 Xaaladda:</strong> ${statusText}</div>
                            ${vesselHtml}
                            ${locationHtml}
                            ${etaHtml}
                            <div><strong>📦 CBM La Isticmaalay:</strong> ${parseFloat(c.used_cbm || 0).toFixed(3)} / ${parseFloat(c.capacity || 0).toFixed(2)}</div>
                        `);
                        $('#containerInfoPanel').show();
                    } else {
                        $('#containerInfoPanel').hide();
                    }
                },
                error: function() {
                    $('#containerInfoPanel').hide();
                }
            });
        } else {
            $('#containerInfoPanel').hide();
        }
    });

    function formatDate(dateString) {
        if (!dateString || dateString == '0000-00-00') return '';
        let d = new Date(dateString);
        return d.toLocaleDateString('so-SO');
    }

    // Load stock items
    function loadStockItems() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stock_items', page: currentPage, search: $('#searchInput').val(), origin_branch_id: $('#originFilter').val(), low_stock_only: $('#lowStockOnly').is(':checked') ? 1 : 0 },
            dataType: 'json',
            success: function(response) {
                $('#stock-table-container').html(response.table_html);
                $('#pagination-container').html(response.pagination_html);
                attachTableEvents();
            },
            error: function() { $('#stock-table-container').html('<div class="text-center p-5"><i class="fas fa-exclamation-triangle"></i><p>Khalad ayaa dhacay</p></div>'); }
        });
    }

    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
            success: function(data) {
                $('#stat-total-items').text(data.stats.total_items || 0);
                $('#stat-total-quantity').text(Number(data.stats.total_quantity || 0).toLocaleString());
                $('#stat-total-volume').text(parseFloat(data.stats.total_volume || 0).toFixed(4));
                $('#stat-total-value').text('$' + parseFloat(data.stats.total_value || 0).toFixed(2));
                $('#stat-low-stock').text(data.stats.low_stock_items || 0);
            }
        });
    }

    function attachTableEvents() {
        $('.view-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href, type: 'POST', data: { ajax_action: 'get_stock_item', id: id },
                success: function(item) {
                    const originMap = { 'china_yiwu': 'Shiinaha (Yiwu) 🇨🇳', 'china_guangzhou': 'Shiinaha (Guangzhou) 🇨🇳', 'dubai': 'Dubaai 🇦🇪', 'local': 'Local' };
                    const originName = item.origin_branch_name ? item.origin_branch_name + (item.origin_branch_code ? ' (' + item.origin_branch_code + ')' : '') : (originMap[item.origin] || '-');
                    $('#viewModalBody').html(`
                        <div class="row"><div class="col-5"><strong>Magaca:</strong></div><div class="col-7"><strong>${escapeHtml(item.stock_name)}</strong></div>
                        <div class="col-5"><strong>Asalka:</strong></div><div class="col-7">${escapeHtml(originName)}</div>
                        <div class="col-5"><strong>Tirada:</strong></div><div class="col-7">${Number(item.quantity).toLocaleString()}</div>
                        <div class="col-5"><strong>Mugga (CBM):</strong></div><div class="col-7">${parseFloat(item.volume_cbm).toFixed(6)} CBM</div>
                        <div class="col-5"><strong>Bakhaarka:</strong></div><div class="col-7">${escapeHtml(item.location || '-')}</div>
                        <div class="col-5"><strong>Qiimaha Halbeeg:</strong></div><div class="col-7">$${parseFloat(item.unit_price).toFixed(2)}</div>
                        <div class="col-5"><strong>Qiimaha Guud:</strong></div><div class="col-7"><strong>$${(parseFloat(item.volume_cbm) * parseFloat(item.unit_price)).toFixed(2)}</strong></div>
                        <div class="col-5"><strong>Macaamiil:</strong></div><div class="col-7">${escapeHtml(item.customer_name || '-')}</div></div>
                    `);
                    $('#viewModal').modal('show');
                }
            });
        });

        $('.edit-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            $.ajax({
                url: window.location.href, type: 'POST', data: { ajax_action: 'get_stock_item', id: id },
                success: function(item) {
                    $('#stockModalLabel').text('Wax Ka Beddel Alaabta');
                    $('#stock_id').val(item.id);
                    selectCustomerForStock({
                        id: item.customer_id || '',
                        customer_name: item.customer_name || '',
                        phone: item.phone || '',
                        balance: item.balance || 0,
                        loyalty_points: item.loyalty_points || 0
                    });
                    $('#modalOrigin').val(item.origin_branch_id || '');
                    $('#modalStockName').val(item.stock_name);
                    $('#modalQuantity').val(item.quantity);
                    $('#modalLength').val(item.length_cm);
                    $('#modalWidth').val(item.width_cm);
                    $('#modalHeight').val(item.height_cm);
                    $('#modalUnitPrice').val(item.unit_price);
                    $('#modalLocation').val(item.location);
                    $('#modalMinStock').val(item.minimum_stock);
                    $('#modalMaxStock').val(item.maximum_stock);
                    $('#modalVolume').val(item.volume_cbm);
                    $('#modalOrigin').trigger('change');
                    updateTotalPreview();
                    $('#stockModal').modal('show');
                }
            });
        });

        $('.move-stock').off('click').on('click', function() {
            moveId = $(this).data('id');
            $('#moveStockName').text($(this).data('name'));
            $('#moveModal').modal('show');
        });

        $('.adjust-stock').off('click').on('click', function() {
            adjustId = $(this).data('id');
            $('#adjustStockName').text($(this).data('name'));
            $('#adjustCurrentQty').text($(this).data('qty'));
            $('#adjustmentQty').val('');
            $('#adjustReason').val('');
            $('#adjustModal').modal('show');
        });

        $('.delete-stock').off('click').on('click', function() {
            deleteId = $(this).data('id');
            $('#deleteStockName').text($(this).data('name'));
            $('#deleteModal').modal('show');
        });

        $('.load-stock').off('click').on('click', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            const qty = $(this).data('qty');
            const origin = $(this).data('origin');
            $('#loadStockId').val(id);
            $('#loadStockName').text(name);
            $('#loadStockQty').text(qty);
            $('#loadQuantity').val('');
            $('#containerInfoPanel').hide();
            $('#loadModal').data('origin', origin);
            $('#loadTripId').html('<option value="">Safarada waa la soo rarayaa...</option>');
            $('#loadModal').modal('show');
            $.ajax({
                url: window.location.href, type: 'POST', data: { ajax_action: 'get_available_trips', origin_branch_id: origin },
                success: function(trips) {
                    let options = '<option value="">Dooro Safarka...</option>';
                    if (!Array.isArray(trips) || trips.length === 0) {
                        options = '<option value="">Kontayner laan isku mid ah lama helin</option>';
                        $('#loadTripId').html(options);
                        $('#containerInfoPanel').hide();
                        return;
                    }
                    trips.forEach(t => { 
                        let statusText = '';
                        switch(t.status) {
                            case 'received': statusText = '(La Helay)'; break;
                            case 'loading': statusText = '(La Rarayaa)'; break;
                            case 'loaded': statusText = '(La Raray)'; break;
                            case 'shipped': statusText = '(Safar Kaga Tagay)'; break;
                            case 'at_port': statusText = '(Dekedda)'; break;
                            default: statusText = '';
                        }
                        options += `<option value="${t.id}">${t.trip_number} - ${t.container_number} ${statusText}</option>`; 
                    });
                    $('#loadTripId').html(options);
                }
            });
        });

        $('.whatsapp-package').off('click').on('click', function() {
            let phone = $(this).data('phone').toString().replace(/\D/g, '');
            const name = $(this).data('name');
            const item = $(this).data('item');
            const qty = $(this).data('qty');
            const cbm = $(this).data('cbm');
            const rate = $(this).data('rate');
            if (phone.length === 9 && (phone.startsWith('6') || phone.startsWith('7'))) phone = '252' + phone;
            if (!phone) { showAlert('error', 'Telefoonka macaamiilka lama helin!'); return; }
            const cbmNumber = parseFloat(String(cbm).replace(/,/g, '')) || 0;
            const rateNumber = parseFloat(String(rate).replace(/,/g, '')) || 0;
            const totalAmount = cbmNumber * rateNumber;
            const totalText = totalAmount > 0 ? totalAmount.toFixed(2) : '0.00';
            let message = `Macmiil: ${name}\n`;
            message += `Alaab update\n`;
            message += `Alaab: ${item}\n`;
            message += `Qty: ${qty}\n`;
            if (cbmNumber > 0) message += `CBM: ${cbmNumber.toFixed(4)}\n`;
            if (rateNumber > 0) message += `Rate: $${rateNumber.toFixed(2)}\n`;
            if (totalAmount > 0) message += `Total: $${totalText}\n`;
            message += `${companyName || 'Shirkadda'}`;
            window.open(`https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(message)}`, '_blank');
        });

        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) { currentPage = page; loadStockItems(); }
        });
    }

    // Stock Form Submit
    let stockSaveInProgress = false;
    $('#stockForm').submit(function(e) {
        e.preventDefault();
        if (stockSaveInProgress) return;
        if (!$('#modalStockName').val()) { showAlert('error', 'Magaca alabta waa lagama maarmaan'); return; }

        stockSaveInProgress = true;
        const $saveBtn = $('#stockForm button[type="submit"]');
        $saveBtn.prop('disabled', true).data('old-text', $saveBtn.html()).html('<i class="fas fa-spinner fa-spin"></i> Kaydin...');

        $.ajax({
            url: window.location.href, type: 'POST',
            data: {
                ajax_action: 'save_stock_item',
                stock_id: $('#stock_id').val(),
                customer_id: $('#modalCustomerId').val(),
                origin_branch_id: $('#modalOrigin').val(),
                stock_name: $('#modalStockName').val(),
                quantity: $('#modalQuantity').val(),
                volume_cbm: $('#modalVolume').val(),
                location: $('#modalLocation').val(),
                unit_price: $('#modalUnitPrice').val(),
                length_cm: $('#modalLength').val(),
                width_cm: $('#modalWidth').val(),
                height_cm: $('#modalHeight').val(),
                minimum_stock: $('#modalMinStock').val(),
                maximum_stock: $('#modalMaxStock').val(),
                send_whatsapp: $('#saveSendWhatsapp').is(':checked') ? 1 : 0
            },
            success: function(res) {
                if (res.success) {
                    $('#stockModal').modal('hide');
                    loadStockItems(); loadStats();
                    showAlert('success', res.message);
                    $('#stockForm')[0].reset();
                    $('#stock_id').val('');
                } else { showAlert('error', res.message); }
            },
            error: function() { showAlert('error', 'Khalad ayaa dhacay'); },
            complete: function() {
                stockSaveInProgress = false;
                const oldText = $saveBtn.data('old-text') || '<i class="fas fa-save"></i> Kaydi';
                $saveBtn.prop('disabled', false).html(oldText);
            }
        });
    });

    // Load Form Submit
    $('#loadForm').submit(function(e) {
        e.preventDefault();
        const qty = parseInt($('#loadQuantity').val());
        const max = parseInt($('#loadStockQty').text());
        if (isNaN(qty) || qty <= 0) { showAlert('error', 'Fadlan geli tirada saxda ah'); return; }
        if (qty > max) { showAlert('error', 'Tirada la rarayo way ka badan tahay kaydka'); return; }
        if (!$('#loadTripId').val()) { showAlert('error', 'Fadlan dooro safarka'); return; }
        
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { 
                ajax_action: 'load_to_container', 
                stock_id: $('#loadStockId').val(), 
                trip_id: $('#loadTripId').val(), 
                quantity: qty, 
                send_whatsapp: $('#loadSendWhatsapp').is(':checked') ? 1 : 0 
            },
            success: function(res) {
                if (res.success) {
                    $('#loadModal').modal('hide');
                    loadStockItems(); loadStats();
                    let msg = res.message;
                    if (res.container && res.container.container_number) {
                        msg += ` Kontaynerka: ${res.container.container_number}`;
                    }
                    showAlert('success', msg);
                } else { showAlert('error', res.message); }
            }
        });
    });

    // Move Form Submit
    $('#moveForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { ajax_action: 'move_stock', id: moveId, new_location: $('#moveLocation').val(), notes: $('#moveNotes').val() },
            success: function(res) {
                if (res.success) { $('#moveModal').modal('hide'); loadStockItems(); showAlert('success', res.message); }
                else { showAlert('error', res.message); }
            }
        });
    });

    // Adjust Form Submit
    $('#adjustForm').submit(function(e) {
        e.preventDefault();
        const adjustment = parseInt($('#adjustmentQty').val());
        if (isNaN(adjustment) || adjustment === 0) { showAlert('error', 'Fadlan geli tirada beddelka'); return; }
        if (!$('#adjustReason').val()) { showAlert('error', 'Fadlan qor sababta'); return; }
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { ajax_action: 'adjust_stock', id: adjustId, adjustment: adjustment, reason: $('#adjustReason').val(), send_whatsapp: $('#adjustSendWhatsapp').is(':checked') && adjustment < 0 ? 1 : 0 },
            success: function(res) {
                if (res.success) { $('#adjustModal').modal('hide'); loadStockItems(); loadStats(); showAlert('success', res.message); }
                else { showAlert('error', res.message); }
            }
        });
    });

    $('#confirmDeleteBtn').click(function() {
        if (deleteId) {
            $.ajax({
                url: window.location.href, type: 'POST', data: { ajax_action: 'delete_stock_item', id: deleteId },
                success: function(res) {
                    if (res.success) { $('#deleteModal').modal('hide'); loadStockItems(); loadStats(); showAlert('success', res.message); }
                    else { showAlert('error', res.message); }
                    deleteId = null;
                }
            });
        }
    });

    $('#addStockBtn, #addStockBtnEmpty').click(function() {
        $('#stockModalLabel').text('Ku Dar Alaabta');
        $('#stockForm')[0].reset();
        $('#stock_id').val('');
        selectCustomerForStock({});
        $('#modalQuantity').val(1);
        $('#modalVolume').val(0);
        $('#modalUnitPrice').val(0);
        $('#modalLength').val(0);
        $('#modalWidth').val(0);
        $('#modalHeight').val(0);
        $('#modalMinStock').val(0);
        $('#modalMaxStock').val(0);
        $('#saveSendWhatsapp').prop('checked', true);
        $('#modalOrigin').trigger('change');
        $('#totalValuePreview').text('0.00');
        $('#stockModal').modal('show');
    });

    $('#applyFilters').click(function() { currentPage = 1; loadStockItems(); });
    $('#resetFilters').click(function() { $('#searchInput').val(''); $('#originFilter').val('all'); $('#lowStockOnly').prop('checked', false); currentPage = 1; loadStockItems(); });
    $('#searchInput').keypress(function(e) { if (e.which === 13) { currentPage = 1; loadStockItems(); } });


    $('#importStockBtn').click(function() {
        $('#importStockForm')[0].reset();
        $('#importResultBox').hide().html('');
        $('#importStockModal').modal('show');
    });

    $('#exportStockBtn').click(function(e) {
        e.preventDefault();
        const params = new URLSearchParams();
        params.set('action', 'export_stock');
        params.set('search', $('#searchInput').val() || '');
        params.set('origin_branch_id', $('#originFilter').val() || 'all');
        params.set('low_stock_only', $('#lowStockOnly').is(':checked') ? '1' : '0');
        window.location.href = '?' + params.toString();
    });

    $('#importStockForm').submit(function(e) {
        e.preventDefault();
        const fileInput = $('#importStockFile')[0];
        if (!fileInput.files || !fileInput.files.length) {
            showAlert('error', 'Fadlan dooro CSV file.');
            return;
        }

        const formData = new FormData(this);
        formData.append('ajax_action', 'import_stock');

        $('#importResultBox').show().html('<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Import ayaa socota...</div>');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    let extra = '';
                    if (res.errors && res.errors.length) {
                        extra = '<hr><strong>Rows la dhaafay:</strong><ul>' + res.errors.map(e => '<li>' + escapeHtml(e) + '</li>').join('') + '</ul>';
                    }
                    $('#importResultBox').html('<div class="alert alert-success">' + escapeHtml(res.message) + extra + '</div>');
                    loadStockItems();
                    loadStats();
                    showAlert('success', res.message);
                } else {
                    $('#importResultBox').html('<div class="alert alert-danger">' + escapeHtml(res.message || 'Import wuu fashilmay') + '</div>');
                    showAlert('error', res.message || 'Import wuu fashilmay');
                }
            },
            error: function() {
                $('#importResultBox').html('<div class="alert alert-danger">Khalad ayaa dhacay inta import-ku socday.</div>');
                showAlert('error', 'Khalad ayaa dhacay inta import-ku socday.');
            }
        });
    });


    // Quick Add Customer
    $('#quickAddCustomerBtn').click(function() { $('#quickCustomerModal').modal('show'); });
    $('#quickCustomerForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { ajax_action: 'quick_add_customer', customer_name: $('#qcName').val(), phone: $('#qcPhone').val(), email: $('#qcEmail').val(), address: $('#qcAddress').val() },
            success: function(res) {
                if (res.success) {
                    selectCustomerForStock({
                        id: res.id,
                        customer_name: res.name,
                        phone: res.phone,
                        balance: 0,
                        loyalty_points: 0
                    });
                    $('#quickCustomerModal').modal('hide');
                    $('#quickCustomerForm')[0].reset();
                    showAlert('success', 'Macaamiil waa la daray!');
                } else { showAlert('error', res.message); }
            }
        });
    });

    // Quick Add Trip
    $('#quickAddTripBtn').click(function() { $('#quickTripModal').modal('show'); });
    $('#quickTripForm').submit(function(e) {
        e.preventDefault();
        $.ajax({
            url: window.location.href, type: 'POST',
            data: { ajax_action: 'quick_add_trip', container_number: $('#qtContainerNumber').val(), container_type: $('#qtContainerType').val(), trip_number: $('#qtTripNumber').val(), origin: $('#qtOrigin').val() },
            success: function(res) {
                if (res.success) {
                    $('#loadTripId').append(`<option value="${res.id}" selected>${res.name}</option>`);
                    $('#quickTripModal').modal('hide');
                    $('#quickTripForm')[0].reset();
                    showAlert('success', 'Safar waa la daray!');
                } else { showAlert('error', res.message); }
            }
        });
    });

    function escapeHtml(text) { if (text === null || text === undefined) return ''; return text.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function showAlert(type, msg) {
        $('#alert-placeholder').html(`<div class="alert alert-${type} alert-dismissible fade show">${msg}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`);
        setTimeout(() => $('.alert').fadeOut(5000), 5000);
    }

    loadStockItems();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>