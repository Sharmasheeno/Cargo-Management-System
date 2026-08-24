<?php
// logistics_functions.php
// Helper functions for logistics module

function getStatusBadge($status, $type = 'shipment') {
    $statuses = [
        'shipment' => [
            'pending' => ['color' => '#6c757d', 'bg' => '#e9ecef', 'icon' => 'fa-clock', 'text' => 'Sugaya'],
            'assigned' => ['color' => '#17a2b8', 'bg' => '#d1ecf1', 'icon' => 'fa-user-check', 'text' => 'La Qoondeeyay'],
            'loading' => ['color' => '#ffc107', 'bg' => '#fff3cd', 'icon' => 'fa-truck-loading', 'text' => 'La Rarayaa'],
            'in_transit' => ['color' => '#fd7e14', 'bg' => '#ffe5d0', 'icon' => 'fa-truck', 'text' => 'Socdaalka'],
            'at_port' => ['color' => '#6f42c1', 'bg' => '#e8eaf6', 'icon' => 'fa-ship', 'text' => 'Dekedda'],
            'delivered' => ['color' => '#28a745', 'bg' => '#d4edda', 'icon' => 'fa-check-circle', 'text' => 'La Gaarsiiyay'],
            'cancelled' => ['color' => '#dc3545', 'bg' => '#f8d7da', 'icon' => 'fa-ban', 'text' => 'La Jojiyay']
        ],
        'container' => [
            'received' => ['color' => '#17a2b8', 'bg' => '#d1ecf1', 'icon' => 'fa-download', 'text' => 'La Helay'],
            'loaded' => ['color' => '#ffc107', 'bg' => '#fff3cd', 'icon' => 'fa-truck-loading', 'text' => 'La Raray'],
            'dispatched' => ['color' => '#fd7e14', 'bg' => '#ffe5d0', 'icon' => 'fa-paper-plane', 'text' => 'La Diray'],
            'at_port' => ['color' => '#6f42c1', 'bg' => '#e8eaf6', 'icon' => 'fa-ship', 'text' => 'Dekedda'],
            'ready' => ['color' => '#28a745', 'bg' => '#d4edda', 'icon' => 'fa-check-circle', 'text' => 'Diyaar'],
            'delivered' => ['color' => '#20c997', 'bg' => '#d1f2eb', 'icon' => 'fa-flag-checkered', 'text' => 'La Gaarsiiyay']
        ]
    ];
    
    $data = $statuses[$type][$status] ?? $statuses[$type]['pending'];
    return '<span class="status-badge" style="background: ' . $data['bg'] . '; color: ' . $data['color'] . '; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><i class="fas ' . $data['icon'] . '"></i> ' . $data['text'] . '</span>';
}

function getProgressPercentage($status) {
    $progress = [
        'pending' => 0,
        'assigned' => 20,
        'loading' => 40,
        'in_transit' => 60,
        'at_port' => 80,
        'delivered' => 100
    ];
    return $progress[$status] ?? 0;
}

function generateTripNumber($pdo, $tenant_id = null) {
    $prefix = 'TRP';
    $year = date('Y');
    $month = date('m');
    
    $sql = "SELECT COUNT(*) as count FROM trucking_trips WHERE trip_number LIKE ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(["{$prefix}{$year}{$month}%"]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
    
    return $prefix . $year . $month . str_pad($count, 4, '0', STR_PAD_LEFT);
}

function getAvailableDrivers($pdo, $tenant_id = null) {
    $sql = "SELECT d.*, u.full_name, u.phone 
            FROM drivers d 
            JOIN users u ON d.user_id = u.id 
            WHERE d.is_active = 1 AND u.is_active = 1";
    if ($tenant_id) {
        $sql .= " AND d.tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenant_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAvailableTrucks($pdo, $tenant_id = null) {
    $sql = "SELECT * FROM trucks WHERE is_active = 1";
    if ($tenant_id) {
        $sql .= " AND tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenant_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAvailableContainers($pdo, $tenant_id = null) {
    $sql = "SELECT * FROM containers WHERE status IN ('received', 'ready')";
    if ($tenant_id) {
        $sql .= " AND tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenant_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCustomersList($pdo, $tenant_id = null) {
    $sql = "SELECT c.*, u.email FROM customers c 
            LEFT JOIN users u ON c.user_id = u.id 
            WHERE c.is_active = 1";
    if ($tenant_id) {
        $sql .= " AND c.tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenant_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAvailableLoaders($pdo, $tenant_id = null) {
    $sql = "SELECT l.*, u.full_name, u.phone 
            FROM loaders l 
            JOIN users u ON l.user_id = u.id 
            WHERE l.is_active = 1 AND u.is_active = 1";
    if ($tenant_id) {
        $sql .= " AND l.tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenant_id]);
    } else {
        $stmt = $pdo->query($sql);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>