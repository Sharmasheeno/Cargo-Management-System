<?php
// ============================================================================
// includes/shipment_functions.php
// Core library for the connected A→Z shipment workflow.
//
// ONE SHIPMENT = ONE OPERATIONAL IDENTITY:
//   Reception → Origin Warehouse → Container/Manifest → Trip
//   → Destination Warehouse → Pickup / Last-Mile Delivery → Finance → Closed
//
// Every helper is defensive and never destroys existing data. The schema
// ensure mirrors the idempotent "addColumnIfMissing" pattern already used in
// branch_manager/containers.php so pages also work on databases that have not
// run sql/migration_shipment_workflow.sql yet.
// ============================================================================

if (!function_exists('shipment_db')) {
    function shipment_db(): PDO {
        global $pdo;
        if (!$pdo instanceof PDO) {
            require_once __DIR__ . '/../config/db_connect.php';
        }
        return $pdo;
    }
}

if (!function_exists('shipmentAddColumn')) {
    function shipmentAddColumn(PDO $pdo, string $table, string $column, string $definition): void {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            if (!(int)$stmt->fetchColumn()) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (Throwable $e) {}
    }
}

if (!function_exists('ensureShipmentSchema')) {
    function ensureShipmentSchema(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        shipmentCreateTables($pdo);
        // Additive columns on EXISTING tables (nullable => zero data risk).
        // NOTE: cargo_manifest_items.shipment_id is a LEGACY column that
        // actually stores TRIP ids (FK fk_cargo_trip -> trucking_trips.id,
        // used by superadmin/tenant_admin warehouse_stock loading flows).
        // The real master-shipment link therefore uses master_shipment_id.
        shipmentAddColumn($pdo, 'packages', 'shipment_id', "INT DEFAULT NULL");
        shipmentAddColumn($pdo, 'warehouse_stock', 'shipment_id', "INT DEFAULT NULL");
        shipmentAddColumn($pdo, 'warehouse_stock', 'branch_id', "INT DEFAULT NULL");
        shipmentAddColumn($pdo, 'cargo_manifest_items', 'master_shipment_id', "INT DEFAULT NULL");
        shipmentAddColumn($pdo, 'trucking_trips', 'approval_status', "ENUM('not_required','pending_approval','approved','rejected') DEFAULT 'not_required'");
        shipmentAddColumn($pdo, 'trucking_trips', 'approved_by', "INT DEFAULT NULL");
        shipmentAddColumn($pdo, 'trucking_trips', 'approved_at', "DATETIME DEFAULT NULL");
        $done = true;
    }
}

if (!function_exists('shipmentCreateTables')) {
    function shipmentCreateTables(PDO $pdo): void {
        $ddls = [];
        // Master shipment identity
        $ddls[] = "CREATE TABLE IF NOT EXISTS shipments (
            id INT NOT NULL AUTO_INCREMENT,
            tenant_id INT NOT NULL,
            shipment_number VARCHAR(50) NOT NULL,
            tracking_number VARCHAR(100) DEFAULT NULL,
            customer_id INT DEFAULT NULL,
            sender_name VARCHAR(255) DEFAULT NULL,
            sender_phone VARCHAR(50) DEFAULT NULL,
            receiver_name VARCHAR(255) DEFAULT NULL,
            receiver_phone VARCHAR(50) DEFAULT NULL,
            receiver_address TEXT DEFAULT NULL,
            origin_branch_id INT DEFAULT NULL,
            destination_branch_id INT DEFAULT NULL,
            cargo_description VARCHAR(255) DEFAULT NULL,
            package_type ENUM('document','parcel','cargo','pallet','container') DEFAULT 'cargo',
            quantity INT NOT NULL DEFAULT 1,
            weight_kg DECIMAL(10,2) DEFAULT 0.00,
            volume_cbm DECIMAL(10,4) DEFAULT 0.0000,
            declared_value DECIMAL(15,2) DEFAULT 0.00,
            delivery_method ENUM('branch_pickup','door_delivery') DEFAULT 'branch_pickup',
            payment_policy VARCHAR(30) DEFAULT 'pay_at_destination',
            current_status VARCHAR(40) NOT NULL DEFAULT 'REGISTERED',
            current_branch_id INT DEFAULT NULL,
            current_warehouse_stock_id INT DEFAULT NULL,
            current_container_id INT DEFAULT NULL,
            current_trip_id INT DEFAULT NULL,
            storage_zone VARCHAR(50) DEFAULT NULL,
            storage_rack VARCHAR(100) DEFAULT NULL,
            source_package_id INT DEFAULT NULL,
            received_at_origin_at DATETIME DEFAULT NULL,
            loaded_at DATETIME DEFAULT NULL,
            dispatched_at DATETIME DEFAULT NULL,
            arrived_at_destination_at DATETIME DEFAULT NULL,
            received_at_destination_at DATETIME DEFAULT NULL,
            ready_at DATETIME DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_shipment_number (tenant_id, shipment_number),
            KEY idx_ship_tenant (tenant_id),
            KEY idx_ship_tracking (tracking_number),
            KEY idx_ship_customer (customer_id),
            KEY idx_ship_status (current_status),
            KEY idx_ship_dest_branch (destination_branch_id, current_status),
            KEY idx_ship_cur_branch (current_branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        // Immutable audit / event history
        $ddls[] = "CREATE TABLE IF NOT EXISTS shipment_events (
            id INT NOT NULL AUTO_INCREMENT,
            tenant_id INT NOT NULL,
            shipment_id INT NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            old_status VARCHAR(40) DEFAULT NULL,
            new_status VARCHAR(40) DEFAULT NULL,
            branch_id INT DEFAULT NULL,
            warehouse_stock_id INT DEFAULT NULL,
            container_id INT DEFAULT NULL,
            trip_id INT DEFAULT NULL,
            location_label VARCHAR(255) DEFAULT NULL,
            performed_by INT DEFAULT NULL,
            performer_name VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            is_public TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sev_shipment (shipment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        // Proof of collection / delivery
        $ddls[] = "CREATE TABLE IF NOT EXISTS shipment_releases (
            id INT NOT NULL AUTO_INCREMENT,
            tenant_id INT NOT NULL,
            shipment_id INT NOT NULL,
            release_type ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
            delivery_assignment_id INT DEFAULT NULL,
            receiver_name VARCHAR(255) NOT NULL,
            receiver_phone VARCHAR(50) DEFAULT NULL,
            verification_method ENUM('otp','phone','id_reference','authorized') DEFAULT 'authorized',
            otp_code_hash VARCHAR(255) DEFAULT NULL,
            quantity_released INT NOT NULL DEFAULT 0,
            released_by INT DEFAULT NULL,
            released_by_name VARCHAR(255) DEFAULT NULL,
            branch_id INT DEFAULT NULL,
            photo_path VARCHAR(255) DEFAULT NULL,
            signature_path VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_srel_shipment (shipment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        foreach ($ddls as $ddl) { try { $pdo->exec($ddl); } catch (Throwable $e) {} }

        // Last-mile delivery assignments (DEL-xxxx)
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_assignments (
            id INT NOT NULL AUTO_INCREMENT,
            tenant_id INT NOT NULL,
            assignment_number VARCHAR(50) NOT NULL,
            shipment_id INT NOT NULL,
            branch_id INT DEFAULT NULL,
            assigned_to INT DEFAULT NULL,
            receiver_name VARCHAR(255) DEFAULT NULL,
            receiver_phone VARCHAR(50) DEFAULT NULL,
            delivery_address TEXT DEFAULT NULL,
            status ENUM('assigned','collected_from_warehouse','out_for_delivery','delivered','failed','returned') DEFAULT 'assigned',
            fail_reason VARCHAR(255) DEFAULT NULL,
            attempts INT DEFAULT 0,
            assigned_by INT DEFAULT NULL,
            collected_at DATETIME DEFAULT NULL,
            out_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_da_number (tenant_id, assignment_number),
            KEY idx_da_agent (assigned_to, status),
            KEY idx_da_shipment (shipment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Throwable $e) {}

        // Driver issue reports (delay / breakdown / incident)
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS trip_issue_reports (
            id INT NOT NULL AUTO_INCREMENT,
            tenant_id INT NOT NULL,
            trip_id INT NOT NULL,
            reported_by INT DEFAULT NULL,
            reporter_name VARCHAR(255) DEFAULT NULL,
            issue_type ENUM('delay','breakdown','incident','other') DEFAULT 'delay',
            description TEXT DEFAULT NULL,
            status ENUM('open','acknowledged','resolved') DEFAULT 'open',
            resolved_notes TEXT DEFAULT NULL,
            resolved_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_tir_trip (trip_id),
            KEY idx_tir_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Throwable $e) {}
    }
}

// ============================================================================
// SHIPMENT STATUS MODEL (state machine)
// ============================================================================
if (!function_exists('shipment_status_labels')) {
    function shipment_status_labels(): array {
        return [
            'REGISTERED' => 'Registered',
            'RECEIVED' => 'Received at Origin Branch',
            'IN_ORIGIN_WAREHOUSE' => 'In Origin Warehouse',
            'READY_FOR_LOADING' => 'Ready for Loading',
            'LOADED' => 'Loaded into Container',
            'DISPATCHED' => 'Dispatched',
            'IN_TRANSIT' => 'In Transit',
            'ARRIVED_AT_DESTINATION' => 'Arrived at Destination',
            'IN_DESTINATION_WAREHOUSE' => 'In Destination Warehouse',
            'READY_FOR_PICKUP' => 'Ready for Pickup',
            'OUT_FOR_DELIVERY' => 'Out for Delivery',
            'DELIVERED' => 'Delivered / Collected',
            'CLOSED' => 'Closed',
            'ON_HOLD' => 'On Hold',
            'DAMAGED' => 'Damaged',
            'PARTIALLY_RECEIVED' => 'Partially Received',
            'DELIVERY_FAILED' => 'Delivery Failed',
            'RETURNED' => 'Returned',
            'CANCELLED' => 'Cancelled',
        ];
    }
}

if (!function_exists('shipment_allowed_transitions')) {
    /** Forward-only operational flow; exception states entered/exited deliberately. */
    function shipment_allowed_transitions(): array {
        return [
            'REGISTERED' => ['RECEIVED', 'ON_HOLD', 'CANCELLED'],
            'RECEIVED' => ['IN_ORIGIN_WAREHOUSE', 'ON_HOLD', 'CANCELLED'],
            'IN_ORIGIN_WAREHOUSE' => ['READY_FOR_LOADING', 'DAMAGED', 'ON_HOLD', 'CANCELLED'],
            'READY_FOR_LOADING' => ['LOADED', 'IN_ORIGIN_WAREHOUSE', 'ON_HOLD'],
            'LOADED' => ['DISPATCHED', 'IN_TRANSIT', 'READY_FOR_LOADING', 'ON_HOLD'],
            'DISPATCHED' => ['IN_TRANSIT', 'ON_HOLD'],
            'IN_TRANSIT' => ['ARRIVED_AT_DESTINATION', 'ON_HOLD'],
            'ARRIVED_AT_DESTINATION' => ['IN_DESTINATION_WAREHOUSE', 'PARTIALLY_RECEIVED'],
            'PARTIALLY_RECEIVED' => ['IN_DESTINATION_WAREHOUSE', 'DAMAGED'],
            'IN_DESTINATION_WAREHOUSE' => ['READY_FOR_PICKUP', 'OUT_FOR_DELIVERY', 'DELIVERED', 'DAMAGED', 'ON_HOLD'],
            'READY_FOR_PICKUP' => ['DELIVERED', 'OUT_FOR_DELIVERY', 'ON_HOLD', 'RETURNED'],
            'OUT_FOR_DELIVERY' => ['DELIVERED', 'DELIVERY_FAILED', 'IN_DESTINATION_WAREHOUSE'],
            'DELIVERY_FAILED' => ['OUT_FOR_DELIVERY', 'IN_DESTINATION_WAREHOUSE', 'RETURNED'],
            'DELIVERED' => ['CLOSED'],
            'RETURNED' => [],
            'DAMAGED' => [],
            'ON_HOLD' => [], // release handled explicitly via resume_shipment()
            'CANCELLED' => [],
            'CLOSED' => [],
        ];
    }
}

if (!function_exists('can_transition_shipment')) {
    function can_transition_shipment(string $from, string $to): bool {
        $map = shipment_allowed_transitions();
        return isset($map[$from]) && in_array($to, $map[$from], true);
    }
}

if (!function_exists('customer_friendly_status')) {
    /** Maps operational truth to the simplified customer/public timeline label. */
    function customer_friendly_status(string $status): string {
        $labels = shipment_status_labels();
        return $labels[$status] ?? ucfirst(strtolower(str_replace('_', ' ', $status)));
    }
}

if (!function_exists('SHIPMENT_STATUS_CONSTANTS')) {
    function SHIPMENT_STATUS_CONSTANTS(): string {
        return '["REGISTERED","RECEIVED","IN_ORIGIN_WAREHOUSE","READY_FOR_LOADING","LOADED","DISPATCHED","IN_TRANSIT","ARRIVED_AT_DESTINATION","IN_DESTINATION_WAREHOUSE","READY_FOR_PICKUP","OUT_FOR_DELIVERY","DELIVERED","CLOSED","ON_HOLD","DAMAGED","PARTIALLY_RECEIVED","DELIVERY_FAILED","RETURNED","CANCELLED"]';
    }
}

// ============================================================================
// NUMBERING
// ============================================================================
if (!function_exists('generate_shipment_number')) {
    function generate_shipment_number(PDO $pdo, int $tenant_id): string {
        try {
            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(shipment_number, 5) AS UNSIGNED)) FROM shipments WHERE tenant_id = ? AND shipment_number LIKE 'SHP-%'");
            $stmt->execute([$tenant_id]);
            $next = (int)$stmt->fetchColumn() + 1;
        } catch (Throwable $e) { $next = 1; }
        do {
            $num = 'SHP-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
            $chk = $pdo->prepare("SELECT id FROM shipments WHERE tenant_id = ? AND shipment_number = ?");
            $chk->execute([$tenant_id, $num]);
            if (!$chk->fetch()) return $num;
            $next++;
        } while (true);
    }
}

if (!function_exists('generate_shipment_tracking')) {
    /** Tracking format: {TENANTCODE}-{BRANCHCODE}-{seq} e.g. DEMO-MGQ-1001 */
    function generate_shipment_tracking(PDO $pdo, int $tenant_id, int $branch_id): string {
        $tcode = 'CMP'; $bcode = 'BR';
        try {
            $st = $pdo->prepare("SELECT code FROM tenants WHERE id = ?"); $st->execute([$tenant_id]);
            $tcode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$st->fetchColumn()) ?: 'CMP');
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT branch_code FROM branches WHERE id = ?"); $st->execute([$branch_id]);
            $bcode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$st->fetchColumn()) ?: 'BR');
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE tenant_id = ? AND origin_branch_id = ?");
            $st->execute([$tenant_id, $branch_id]);
            $seq = (int)$st->fetchColumn() + 1001;
        } catch (Throwable $e) { $seq = 1001; }
        do {
            $trk = "{$tcode}-{$bcode}-{$seq}";
            $chk = $pdo->prepare("SELECT id FROM shipments WHERE tracking_number = ?");
            $chk->execute([$trk]);
            if (!$chk->fetch()) return $trk;
            $seq++;
        } while (true);
    }
}

if (!function_exists('generate_delivery_assignment_number')) {
    function generate_delivery_assignment_number(PDO $pdo, int $tenant_id): string {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_assignments WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
            $next = (int)$stmt->fetchColumn() + 1001;
        } catch (Throwable $e) { $next = 1001; }
        return 'DEL-' . $next;
    }
}

// ============================================================================
// EVENT HISTORY + STATUS ENGINE
// ============================================================================
if (!function_exists('log_shipment_event')) {
    /**
     * Append an immutable, auditable event. $data keys: tenant_id, shipment_id,
     * event_type, old_status, new_status, branch_id, warehouse_stock_id,
     * container_id, trip_id, location_label, performed_by, performer_name,
     * notes, is_public
     */
    function log_shipment_event(array $data): int {
        $pdo = shipment_db();
        $stmt = $pdo->prepare("INSERT INTO shipment_events
            (tenant_id, shipment_id, event_type, old_status, new_status, branch_id,
             warehouse_stock_id, container_id, trip_id, location_label,
             performed_by, performer_name, notes, is_public, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([
            (int)$data['tenant_id'],
            (int)$data['shipment_id'],
            (string)$data['event_type'],
            $data['old_status'] ?? null,
            $data['new_status'] ?? null,
            $data['branch_id'] ?? null,
            $data['warehouse_stock_id'] ?? null,
            $data['container_id'] ?? null,
            $data['trip_id'] ?? null,
            $data['location_label'] ?? null,
            $data['performed_by'] ?? null,
            $data['performer_name'] ?? null,
            $data['notes'] ?? null,
            isset($data['is_public']) ? (int)(bool)$data['is_public'] : 1,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('sync_package_from_shipment')) {
    /**
     * Keep the legacy `packages` row (customer pipeline) as a VIEW of the
     * master shipment so existing customer pages / public tracking never show
     * a stale independent status.
     */
    function sync_package_from_shipment(int $shipment_id): void {
        $pdo = shipment_db();
        $map = [
            'REGISTERED' => 'pending',
            'RECEIVED' => 'received',
            'IN_ORIGIN_WAREHOUSE' => 'warehouse',
            'READY_FOR_LOADING' => 'warehouse',
            'LOADED' => 'in_transit',
            'DISPATCHED' => 'in_transit',
            'IN_TRANSIT' => 'in_transit',
            'ARRIVED_AT_DESTINATION' => 'warehouse',
            'PARTIALLY_RECEIVED' => 'warehouse',
            'IN_DESTINATION_WAREHOUSE' => 'warehouse',
            'READY_FOR_PICKUP' => 'warehouse',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'CLOSED' => 'delivered',
            'DELIVERY_FAILED' => 'out_for_delivery',
            'RETURNED' => 'cancelled',
            'CANCELLED' => 'cancelled',
            'ON_HOLD' => null,
            'DAMAGED' => null,
        ];
        try {
            $st = $pdo->prepare("SELECT current_status, current_branch_id, delivered_at FROM shipments WHERE id = ?");
            $st->execute([$shipment_id]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            if (!$s) return;
            $pkgStatus = $map[$s['current_status']] ?? null;
            if ($pkgStatus === null) return;
            $upd = $pdo->prepare("UPDATE packages SET status = ?, current_branch_id = COALESCE(?, current_branch_id),
                                    delivered_date = COALESCE(delivered_date, ?), updated_at = NOW()
                                  WHERE shipment_id = ?");
            $upd->execute([$pkgStatus, $s['current_branch_id'], $s['delivered_at'], $shipment_id]);
        } catch (Throwable $e) { error_log('sync_package_from_shipment: ' . $e->getMessage()); }
    }
}

if (!function_exists('update_shipment_status')) {
    /**
     * The ONLY sanctioned way to change a shipment's operational status.
     * Validates the transition, writes the audit event and mirrors the state
     * onto the legacy packages row automatically.
     * $ctx: tenant_id (required) + optional branch_id, container_id, trip_id,
     *       warehouse_stock_id, location_label, performed_by, performer_name,
     *       notes, is_public, force, current_* overrides, event_type
     * Returns [ok => bool, message => string, old_status?]
     */
    function update_shipment_status(int $shipment_id, string $new_status, array $ctx = []): array {
        $pdo = shipment_db();
        $valid = json_decode(SHIPMENT_STATUS_CONSTANTS(), true);
        if (!in_array($new_status, $valid, true)) {
            return ['ok' => false, 'message' => "Unknown shipment status '{$new_status}'."];
        }
        $st = $pdo->prepare("SELECT * FROM shipments WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $st->execute([$shipment_id, (int)$ctx['tenant_id']]);
        $ship = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ship) return ['ok' => false, 'message' => 'Shipment not found.'];
        $old = (string)$ship['current_status'];
        if ($old === $new_status) return ['ok' => true, 'message' => 'No change.', 'old_status' => $old];
        if (empty($ctx['force']) && !can_transition_shipment($old, $new_status)) {
            return ['ok' => false, 'message' => "Invalid transition {$old} to {$new_status}."];
        }
        $timeCols = [
            'RECEIVED' => 'received_at_origin_at',
            'LOADED' => 'loaded_at',
            'DISPATCHED' => 'dispatched_at',
            'ARRIVED_AT_DESTINATION' => 'arrived_at_destination_at',
            'IN_DESTINATION_WAREHOUSE' => 'received_at_destination_at',
            'READY_FOR_PICKUP' => 'ready_at',
            'DELIVERED' => 'delivered_at',
            'CLOSED' => 'closed_at',
        ];
        $sets = ["current_status = ?", "updated_at = NOW()"];
        $params = [$new_status];
        if (isset($timeCols[$new_status]) && empty($ship[$timeCols[$new_status]])) {
            $sets[] = "`{$timeCols[$new_status]}` = NOW()";
        }
        foreach (['current_branch_id','current_container_id','current_trip_id','storage_zone','storage_rack'] as $f) {
            if (array_key_exists($f, $ctx)) { $sets[] = "$f = ?"; $params[] = $ctx[$f]; }
        }
        $params[] = $shipment_id;
        $pdo->prepare("UPDATE shipments SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        log_shipment_event([
            'tenant_id' => $ctx['tenant_id'],
            'shipment_id' => $shipment_id,
            'event_type' => $ctx['event_type'] ?? ('STATUS_' . $new_status),
            'old_status' => $old,
            'new_status' => $new_status,
            'branch_id' => $ctx['branch_id'] ?? $ship['current_branch_id'],
            'warehouse_stock_id' => $ctx['warehouse_stock_id'] ?? null,
            'container_id' => $ctx['container_id'] ?? null,
            'trip_id' => $ctx['trip_id'] ?? null,
            'location_label' => $ctx['location_label'] ?? null,
            'performed_by' => $ctx['performed_by'] ?? ($_SESSION['user_id'] ?? null),
            'performer_name' => $ctx['performer_name'] ?? ($_SESSION['user_name'] ?? null),
            'notes' => $ctx['notes'] ?? null,
            'is_public' => $ctx['is_public'] ?? 1,
        ]);
        sync_package_from_shipment($shipment_id);
        return ['ok' => true, 'message' => 'Status updated.', 'old_status' => $old];
    }
}

if (!function_exists('resume_shipment_from_hold')) {
    /** Explicit correction workflow for ON_HOLD shipments. */
    function resume_shipment_from_hold(int $shipment_id, string $to_status, array $ctx = []): array {
        $ctx['force'] = true;
        $ctx['event_type'] = 'HOLD_RELEASED';
        return update_shipment_status($shipment_id, $to_status, $ctx);
    }
}

// ============================================================================
// WAREHOUSE / STOCK MOVEMENT TRACEABILITY
// Every movement answers: what, how much, from where, to where, when, who,
// why and WHICH SHIPMENT (warehouse_stock.shipment_id +
// stock_movements.reference_type='shipment').
// ============================================================================
if (!function_exists('record_stock_movement')) {
    function record_stock_movement(array $m): int {
        $pdo = shipment_db();
        // stock_movements.created_by carries an FK to users(id) in existing
        // installs: only pass a user id that actually exists, else NULL.
        $userId = $m['created_by'] ?? ($_SESSION['user_id'] ?? null);
        if ($userId !== null) {
            try {
                $chk = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $chk->execute([(int)$userId]);
                if (!$chk->fetchColumn()) $userId = null;
            } catch (Throwable $e) { $userId = null; }
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO stock_movements
                (tenant_id, warehouse_stock_id, quantity_change, previous_quantity,
                 new_quantity, movement_type, reference_type, reference_id, notes, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->execute([
                (int)$m['tenant_id'], (int)$m['warehouse_stock_id'],
                (int)$m['quantity_change'], (int)$m['previous_quantity'], (int)$m['new_quantity'],
                (string)$m['movement_type'],
                $m['reference_type'] ?? 'shipment',
                $m['reference_id'] ?? null,
                $m['notes'] ?? null,
                $userId,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('record_stock_movement: ' . $e->getMessage());
            return 0; // never block the operational flow on logging failure
        }
    }
}

if (!function_exists('create_shipment_from_reception')) {
    /**
     * Called by staff/receptions.php after a `packages` row is inserted.
     * Creates the master shipment identity for the package WITHOUT duplicating
     * any warehouse cargo record. Returns the shipment id (0 on failure).
     */
    function create_shipment_from_reception(array $pkg, int $tenant_id, int $branch_id, ?int $destination_branch_id = null): int {
        $pdo = shipment_db();
        ensureShipmentSchema($pdo);
        try {
            $num = generate_shipment_number($pdo, $tenant_id);
            $trk = generate_shipment_tracking($pdo, $tenant_id, $branch_id);
            $ins = $pdo->prepare("INSERT INTO shipments
                (tenant_id, shipment_number, tracking_number, customer_id,
                 sender_name, sender_phone, receiver_name, receiver_phone,
                 cargo_description, package_type, quantity, weight_kg,
                 volume_cbm, declared_value, delivery_method,
                 current_status, current_branch_id, origin_branch_id, destination_branch_id,
                 source_package_id, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $ins->execute([
                $tenant_id, $num, $trk, $pkg['customer_id'] ?? null,
                $pkg['customer_name'] ?? null, $pkg['customer_phone'] ?? null,
                $pkg['receiver_name'] ?? ($pkg['customer_name'] ?? null),
                $pkg['receiver_phone'] ?? ($pkg['customer_phone'] ?? null),
                $pkg['package_name'] ?? null, $pkg['package_type'] ?? 'cargo',
                max(1, (int)($pkg['quantity'] ?? 1)), (float)($pkg['weight_kg'] ?? 0),
                (float)($pkg['volume_cbm'] ?? 0), (float)($pkg['declared_value'] ?? 0),
                (($pkg['delivery_method'] ?? 'branch_pickup') === 'door_delivery') ? 'door_delivery' : 'branch_pickup',
                'REGISTERED',
                $branch_id, $branch_id, $destination_branch_id,
                $pkg['id'] ?? null, $_SESSION['user_id'] ?? null,
            ]);
            $sid = (int)$pdo->lastInsertId();
            if (!empty($pkg['id'])) {
                $pdo->prepare("UPDATE packages SET shipment_id = ? WHERE id = ?")->execute([$sid, $pkg['id']]);
            }
            log_shipment_event([
                'tenant_id' => $tenant_id, 'shipment_id' => $sid,
                'event_type' => 'REGISTERED', 'new_status' => 'REGISTERED',
                'branch_id' => $branch_id, 'location_label' => $pkg['current_location'] ?? null,
                'performed_by' => $_SESSION['user_id'] ?? null,
                'performer_name' => $_SESSION['user_name'] ?? null,
                'notes' => "Shipment registered at reception. Tracking: {$trk}",
            ]);
            if (($pkg['status'] ?? 'pending') !== 'pending') {
                update_shipment_status($sid, 'RECEIVED', [
                    'tenant_id' => $tenant_id, 'branch_id' => $branch_id,
                    'event_type' => 'RECEIVED', 'notes' => 'Received at reception intake.',
                ]);
            }
            return $sid;
        } catch (Throwable $e) {
            error_log('create_shipment_from_reception: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('receive_shipment_into_warehouse')) {
    /**
     * Warehouse receiving (origin OR destination): creates ONE warehouse_stock
     * row bound to the shipment, logs an IN movement, updates status and
     * propagates customer tracking automatically. Any previous active stock
     * rows for the same shipment are closed, so a shipment can never be
     * physically available in two warehouses at once.
     */
    function receive_shipment_into_warehouse(int $shipment_id, int $branch_id, string $zone, string $rack, array $ctx = []): array {
        $pdo = shipment_db();
        ensureShipmentSchema($pdo);
        $tenantId = (int)$ctx['tenant_id'];
        $st = $pdo->prepare("SELECT * FROM shipments WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $st->execute([$shipment_id, $tenantId]);
        $ship = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ship) return ['ok' => false, 'message' => 'Shipment not found.'];

        $pdo->prepare("UPDATE warehouse_stock SET is_active = 0,
                        notes = CONCAT(COALESCE(notes,''), ' [closed: moved out]')
                        WHERE shipment_id = ? AND tenant_id = ? AND is_active = 1")
            ->execute([$shipment_id, $tenantId]);

        $qty = max(1, (int)$ship['quantity']);
        $ins = $pdo->prepare("INSERT INTO warehouse_stock
            (tenant_id, customer_id, origin, stock_name, quantity, volume_cbm,
             unit_price, location, bin_location, zone, shipment_id, branch_id,
             mogadishu_status, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $ins->execute([
            $tenantId, $ship['customer_id'], 'local',
            ($ship['cargo_description'] ?: ('Shipment ' . $ship['shipment_number'])),
            $qty, (float)$ship['volume_cbm'],
            0, 'Warehouse', $rack ?: null, $zone ?: null,
            $shipment_id, $branch_id,
            'in_warehouse', 1,
        ]);
        $wsId = (int)$pdo->lastInsertId();

        record_stock_movement([
            'tenant_id' => $tenantId, 'warehouse_stock_id' => $wsId,
            'quantity_change' => $qty, 'previous_quantity' => 0, 'new_quantity' => $qty,
            'movement_type' => 'in',
            'reference_type' => 'shipment', 'reference_id' => $shipment_id,
            'notes' => 'IN: Reception to Warehouse' . (!empty($ctx['notes']) ? ' - ' . $ctx['notes'] : ''),
            'created_by' => $_SESSION['user_id'] ?? null,
        ]);

        $locLabel = trim(($ctx['branch_name'] ?? '') . ' Warehouse'
            . ($zone ? " / Zone {$zone}" : '') . ($rack ? " / Rack {$rack}" : ''));
        $isDest = !empty($ctx['is_destination']);
        $res = update_shipment_status(
            $shipment_id,
            $isDest ? 'IN_DESTINATION_WAREHOUSE' : 'IN_ORIGIN_WAREHOUSE',
            array_merge($ctx, [
                'warehouse_stock_id' => $wsId,
                'current_branch_id' => $branch_id,
                'storage_zone' => $zone ?: null,
                'storage_rack' => $rack ?: null,
                'location_label' => $locLabel,
                'event_type' => $isDest ? 'RECEIVED_AT_DESTINATION_WAREHOUSE' : 'WAREHOUSE_RECEIVED',
                'notes' => "Stored at {$locLabel}. Quantity received: {$qty}/{$qty}.",
            ])
        );
        if (!$res['ok']) return ['ok' => false, 'message' => $res['message']];
        $pdo->prepare("UPDATE shipments SET current_warehouse_stock_id = ? WHERE id = ?")->execute([$wsId, $shipment_id]);
        return ['ok' => true, 'message' => "Shipment stored at {$locLabel}.", 'warehouse_stock_id' => $wsId];
    }
}

if (!function_exists('load_shipment_into_container')) {
    /**
     * Manifest linkage: shipment → cargo_manifest_items → container.
     * Validates: at origin warehouse, not already fully loaded elsewhere,
     * quantity within availability, CBM capacity respected where supported.
     */
    function load_shipment_into_container(int $shipment_id, int $container_id, int $quantity, array $ctx = []): array {
        $pdo = shipment_db();
        ensureShipmentSchema($pdo);
        $tenantId = (int)$ctx['tenant_id'];
        $st = $pdo->prepare("SELECT * FROM shipments WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $st->execute([$shipment_id, $tenantId]);
        $ship = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ship) return ['ok' => false, 'message' => 'Shipment not found.'];

        if (!in_array($ship['current_status'], ['IN_ORIGIN_WAREHOUSE', 'READY_FOR_LOADING'], true)) {
            return ['ok' => false, 'message' => "Shipment is {$ship['current_status']}; it must be in the origin warehouse before loading."];
        }
        $ct = $pdo->prepare("SELECT * FROM containers WHERE id = ? AND tenant_id = ?");
        $ct->execute([$container_id, $tenantId]);
        $container = $ct->fetch(PDO::FETCH_ASSOC);
        if (!$container) return ['ok' => false, 'message' => 'Container not found.'];
        // Physical-location guard: a shipment can only be loaded into a container
        // that is at the same branch. Prevents a supervisor at branch A from
        // loading cargo physically held at branch B into a local container.
        $shipBranch = (int)($ship['current_branch_id'] ?? $ship['origin_branch_id'] ?? 0);
        $contBranch = (int)($container['current_branch_id'] ?? $container['origin_branch_id'] ?? 0);
        if ($shipBranch > 0 && $contBranch > 0 && $shipBranch !== $contBranch) {
            return ['ok' => false, 'message' => 'Shipment is not physically at this container\'s branch and cannot be loaded here.'];
        }
        if ((int)$quantity > (int)$ship['quantity']) {
            return ['ok' => false, 'message' => "Quantity {$quantity} exceeds available {$ship['quantity']}."];
        }
        // CBM capacity check where the container has a declared size.
        $cbmNeeded = (float)$ship['volume_cbm'] * ((float)$quantity / max(1, (int)$ship['quantity']));
        try {
            $capStmt = $pdo->prepare("
                SELECT c.size_cbm,
                       COALESCE((SELECT SUM(cmi.cbm_used) FROM cargo_manifest_items cmi WHERE cmi.container_id = c.id),0) AS used_cbm
                FROM containers c WHERE c.id = ?");
            $capStmt->execute([$container_id]);
            $cap = $capStmt->fetch(PDO::FETCH_ASSOC);
            if ($cap && (float)$cap['size_cbm'] > 0 && ((float)$cap['used_cbm'] + $cbmNeeded) > (float)$cap['size_cbm']) {
                return ['ok' => false, 'message' => sprintf('Container capacity exceeded: %.2f CBM free, need %.2f CBM.', (float)$cap['size_cbm'] - (float)$cap['used_cbm'], $cbmNeeded)];
            }
        } catch (Throwable $e) {}

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO cargo_manifest_items
                (tenant_id, container_id, master_shipment_id, stock_name, quantity, cbm_used,
                 weight_kg, added_at, mogadishu_status)
                VALUES (?,?,?,?,?,?,?,NOW(),'in_warehouse')");
            $ins->execute([$tenantId, $container_id, $shipment_id,
                $ship['cargo_description'], $quantity, $cbmNeeded, (float)$ship['weight_kg']]);

            // OUT / LOAD movement from the origin warehouse stock row.
            if (!empty($ship['current_warehouse_stock_id'])) {
                $wsStmt = $pdo->prepare("SELECT id, quantity FROM warehouse_stock WHERE id = ? AND tenant_id = ? FOR UPDATE");
                $wsStmt->execute([(int)$ship['current_warehouse_stock_id'], $tenantId]);
                $ws = $wsStmt->fetch(PDO::FETCH_ASSOC);
                if ($ws) {
                    $newQty = max(0, (int)$ws['quantity'] - (int)$quantity);
                    // warehouse_stock.updated_by carries an FK to users(id):
                    // validate before writing, else leave untouched.
                    $updBy = $_SESSION['user_id'] ?? null;
                    if ($updBy !== null) {
                        $uChk = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                        $uChk->execute([(int)$updBy]);
                        if (!$uChk->fetchColumn()) $updBy = null;
                    }
                    $sql = "UPDATE warehouse_stock SET quantity = ?, mogadishu_status = 'taken', last_updated = NOW()"
                         . ($updBy !== null ? ", updated_by = " . (int)$updBy : "") . " WHERE id = ?";
                    $pdo->prepare($sql)->execute([$newQty, $ws['id']]);
                    record_stock_movement([
                        'tenant_id' => $tenantId, 'warehouse_stock_id' => $ws['id'],
                        'quantity_change' => -(int)$quantity,
                        'previous_quantity' => (int)$ws['quantity'], 'new_quantity' => $newQty,
                        'movement_type' => 'out',
                        'reference_type' => 'shipment', 'reference_id' => $shipment_id,
                        'notes' => "LOAD: Warehouse to Container {$container['container_number']}",
                        'created_by' => $_SESSION['user_id'] ?? null,
                    ]);
                }
            }

            $res = update_shipment_status($shipment_id, 'LOADED', array_merge($ctx, [
                'current_container_id' => $container_id,
                'location_label' => 'Container ' . $container['container_number'],
                'event_type' => 'LOADED_INTO_CONTAINER',
                'notes' => "Loaded {$quantity} of {$ship['quantity']} into container {$container['container_number']}.",
            ]));
            if (!$res['ok']) { throw new RuntimeException($res['message']); }
            $pdo->commit();
            return ['ok' => true, 'message' => "Shipment loaded into container {$container['container_number']}."];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

// ============================================================================
// TRIP → SHIPMENT STATUS PROPAGATION (automatic customer tracking)
// Only shipments actually loaded on the trip's container are updated; truck
// arrival at destination NEVER equals DELIVERED.
// ============================================================================
if (!function_exists('propagate_trip_status_to_shipments')) {
    function propagate_trip_status_to_shipments(int $trip_id, string $trip_status, array $ctx = []): void {
        $pdo = shipment_db();
        $tenantId = (int)$ctx['tenant_id'];
        $st = $pdo->prepare("SELECT t.*, c.container_number
                             FROM trucking_trips t LEFT JOIN containers c ON c.id = t.container_id
                             WHERE t.id = ? AND t.tenant_id = ?");
        $st->execute([$trip_id, $tenantId]);
        $trip = $st->fetch(PDO::FETCH_ASSOC);
        if (!$trip || empty($trip['container_id'])) return;

        // Shipments actually loaded on this container (via the true master link;
        // legacy cmi.shipment_id stores trip ids and must not be used here)
        $shipStmt = $pdo->prepare("
            SELECT s.id, s.current_status FROM shipments s
            JOIN cargo_manifest_items cmi ON cmi.master_shipment_id = s.id
            WHERE cmi.container_id = ? AND cmi.tenant_id = ?
              AND s.is_active = 1
              AND s.current_status IN ('LOADED','DISPATCHED','IN_TRANSIT','ARRIVED_AT_DESTINATION')");
        $shipStmt->execute([$trip['container_id'], $tenantId]);
        $shipments = $shipStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$shipments) return;

        // Trip lifecycle → shipment status. 'delivered'/'completed' trips mean
        // the container reached the destination branch: shipments become
        // ARRIVED_AT_DESTINATION (awaiting warehouse receipt), NOT delivered.
        $map = [
            'loading' => 'LOADED',
            'loaded' => 'LOADED',
            'in_transit' => 'IN_TRANSIT',
            'delivered' => 'ARRIVED_AT_DESTINATION',
            'completed' => 'ARRIVED_AT_DESTINATION',
        ];
        $target = $map[$trip_status] ?? null;
        if (!$target) return;

        // Dispatch approval gate (defense-in-depth): an unapproved/rejected
        // trip has not legally departed, so shipments must NOT show IN_TRANSIT.
        if ($target === 'IN_TRANSIT') {
            $appr = $pdo->prepare("SELECT COALESCE(approval_status,'not_required') FROM trucking_trips WHERE id = ?");
            $appr->execute([$trip_id]);
            $approval = (string)$appr->fetchColumn();
            if ($approval === 'pending_approval' || $approval === 'rejected') return;
        }

        foreach ($shipments as $s) {
            update_shipment_status((int)$s['id'], $target, [
                'tenant_id' => $tenantId,
                'current_trip_id' => $trip_id,
                'current_container_id' => (int)$trip['container_id'],
                'branch_id' => $trip['from_branch_id'] ?? null,
                'location_label' => !empty($trip['container_number']) ? ('Container ' . $trip['container_number']) : null,
                'event_type' => 'TRIP_' . strtoupper($trip_status),
                'performed_by' => $ctx['performed_by'] ?? ($_SESSION['user_id'] ?? null),
                'performer_name' => $ctx['performer_name'] ?? ($_SESSION['user_name'] ?? null),
                'notes' => 'Trip ' . $trip['trip_number'] . ' - ' . $trip_status,
            ]);
        }
    }
}

// ============================================================================
// CUSTOMER / PUBLIC TRACKING VIEW (derived from operational truth)
// ============================================================================
if (!function_exists('log_dispatch_approved')) {
    /**
     * Audit trail when a Branch Manager approves dispatch: one DISPATCH_APPROVED
     * event per shipment loaded on the trip's container.
     */
    function log_dispatch_approved(int $trip_id, int $tenant_id): void {
        $pdo = shipment_db();
        $t = $pdo->prepare("SELECT container_id, from_branch_id FROM trucking_trips WHERE id = ? AND tenant_id = ?");
        $t->execute([$trip_id, $tenant_id]);
        $trip = $t->fetch(PDO::FETCH_ASSOC);
        if (!$trip || empty($trip['container_id'])) return;
        try {
            $pdo->prepare("INSERT INTO shipment_events
                (tenant_id, shipment_id, event_type, new_status, branch_id, container_id, trip_id,
                 performed_by, performer_name, notes, created_at)
                SELECT s.tenant_id, s.id, 'DISPATCH_APPROVED', s.current_status, ?, ?, ?, ?, ?, ?, NOW()
                FROM cargo_manifest_items cmi JOIN shipments s ON s.id = cmi.master_shipment_id
                WHERE cmi.container_id = ?")
                ->execute([
                    $trip['from_branch_id'], $trip['container_id'], $trip_id,
                    $_SESSION['user_id'] ?? null, $_SESSION['user_name'] ?? null,
                    'Dispatch approved by Branch Manager', $trip['container_id'],
                ]);
        } catch (Throwable $e) {
            error_log('log_dispatch_approved: ' . $e->getMessage());
        }
    }
}
if (!function_exists('get_shipment_tracking_timeline')) {
    /**
     * Customer-safe tracking timeline: only is_public events; never internal
     * financial notes or warehouse security details.
     */
    function get_shipment_tracking_timeline(string $tracking_number, ?int $tenant_id = null): array {
        $pdo = shipment_db();
        ensureShipmentSchema($pdo);
        $sql = "SELECT s.id, s.shipment_number, s.tracking_number, s.cargo_description,
                       s.quantity, s.weight_kg, s.volume_cbm, s.current_status,
                       s.origin_branch_id, s.destination_branch_id,
                       ob.branch_name AS origin_name, db.branch_name AS destination_name,
                       s.delivered_at, s.created_at
                FROM shipments s
                LEFT JOIN branches ob ON ob.id = s.origin_branch_id
                LEFT JOIN branches db ON db.id = s.destination_branch_id
                WHERE (s.tracking_number = ? OR s.shipment_number = ?) AND s.is_active = 1";
        $params = [$tracking_number, $tracking_number];
        if ($tenant_id) { $sql .= " AND s.tenant_id = ?"; $params[] = $tenant_id; }
        $st = $pdo->prepare($sql . " LIMIT 1");
        $st->execute($params);
        $shipment = $st->fetch(PDO::FETCH_ASSOC);
        if (!$shipment) return [null, []];

        $ev = $pdo->prepare("SELECT event_type, new_status, location_label, notes, created_at
                             FROM shipment_events
                             WHERE shipment_id = ? AND is_public = 1
                             ORDER BY created_at ASC, id ASC");
        $ev->execute([$shipment['id']]);
        return [$shipment, $ev->fetchAll(PDO::FETCH_ASSOC)];
    }
}
?>
