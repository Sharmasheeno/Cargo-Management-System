<?php
// ============================================================================
// api/shipments.php — Customer-safe shipment tracking API (JSON)
// ----------------------------------------------------------------------------
// GET ?tracking=SHP-1001  or  ?tracking=DEMO-MGQ-1001
// Optional &tenant=<id> to pin the tenant context.
//
// Returns ONLY the derived operational truth of the master shipment:
// a status timeline built from public shipment_events. Never exposes internal
// financial notes, warehouse security details, staff private data, tenant
// secrets or full internal manifests.
// ============================================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/shipment_functions.php';

header('Content-Type: application/json; charset=utf-8');

$tracking = trim((string)($_GET['tracking'] ?? $_GET['q'] ?? ''));
if ($tracking === '') {
    echo json_encode(['success' => false, 'message' => 'Tracking number required.']);
    exit;
}

$tenantId = isset($_GET['tenant']) && is_numeric($_GET['tenant']) ? (int)$_GET['tenant'] : null;

list($shipment, $events) = get_shipment_tracking_timeline($tracking, $tenantId);
if (!$shipment) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No shipment found for that tracking number.']);
    exit;
}

echo json_encode([
    'success' => true,
    'shipment' => [
        'shipment_number' => $shipment['shipment_number'],
        'tracking_number' => $shipment['tracking_number'],
        'cargo_description' => $shipment['cargo_description'],
        'quantity' => (int)$shipment['quantity'],
        'weight_kg' => (float)$shipment['weight_kg'],
        'origin' => $shipment['origin_name'],
        'destination' => $shipment['destination_name'],
        'status' => $shipment['current_status'],
        'status_label' => customer_friendly_status($shipment['current_status']),
        'created_at' => $shipment['created_at'],
        'delivered_at' => $shipment['delivered_at'],
    ],
    'timeline' => array_map(function ($e) {
        return [
            'status_label' => !empty($e['new_status']) ? customer_friendly_status($e['new_status']) : null,
            'location' => $e['location_label'],
            'note' => $e['notes'],
            'time' => $e['created_at'],
        ];
    }, $events),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
