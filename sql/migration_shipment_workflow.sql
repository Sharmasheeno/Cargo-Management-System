-- ============================================================================
-- MIGRATION: Single connected A→Z shipment workflow
-- Curdun Cargo Management System
-- ----------------------------------------------------------------------------
-- SAFE / ADDITIVE ONLY:
--   * Creates NEW tables (shipments, shipment_events, shipment_releases,
--     delivery_assignments, trip_issue_reports)
--   * Adds nullable link columns to EXISTING tables (no data destroyed)
--   * Backfills one master shipment per existing package row (legacy safe)
-- Rollback: DROP the 5 new tables; drop the added columns listed below.
-- ============================================================================

CREATE TABLE IF NOT EXISTS shipments (
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
  length_cm DECIMAL(10,2) DEFAULT 0.00,
  width_cm DECIMAL(10,2) DEFAULT 0.00,
  height_cm DECIMAL(10,2) DEFAULT 0.00,
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
  source_package_id INT DEFAULT NULL COMMENT 'Link back to legacy packages row',
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

CREATE TABLE IF NOT EXISTS shipment_events (
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
  is_public TINYINT(1) DEFAULT 1 COMMENT 'Safe to show to customer/public tracking',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sev_shipment (shipment_id),
  KEY idx_sev_tenant (tenant_id),
  CONSTRAINT fk_sev_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipment_releases (
  id INT NOT NULL AUTO_INCREMENT,
  tenant_id INT NOT NULL,
  shipment_id INT NOT NULL,
  release_type ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
  delivery_assignment_id INT DEFAULT NULL,
  receiver_name VARCHAR(255) NOT NULL,
  receiver_phone VARCHAR(50) DEFAULT NULL,
  verification_method ENUM('otp','phone','id_reference','authorized') DEFAULT 'authorized',
  otp_code_hash VARCHAR(255) DEFAULT NULL COMMENT 'Hashed; raw OTP never stored',
  quantity_released INT NOT NULL DEFAULT 0,
  released_by INT DEFAULT NULL,
  released_by_name VARCHAR(255) DEFAULT NULL,
  branch_id INT DEFAULT NULL,
  photo_path VARCHAR(255) DEFAULT NULL,
  signature_path VARCHAR(255) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  released_at DATETIME DEFAULT CURRENT_TIMESTAMP,

CREATE TABLE IF NOT EXISTS delivery_assignments (
  id INT NOT NULL AUTO_INCREMENT,
  tenant_id INT NOT NULL,
  assignment_number VARCHAR(50) NOT NULL,
  shipment_id INT NOT NULL,
  branch_id INT DEFAULT NULL,
  assigned_to INT DEFAULT NULL COMMENT 'Delivery agent user id',
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
  KEY idx_da_shipment (shipment_id),
  CONSTRAINT fk_da_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_issue_reports (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. ADDITIVE LINK COLUMNS on EXISTING tables (nullable => no data risk)
-- ----------------------------------------------------------------------------
ALTER TABLE packages
  ADD COLUMN IF NOT EXISTS shipment_id INT DEFAULT NULL COMMENT 'Master shipment link',
  ADD KEY IF NOT EXISTS idx_packages_shipment (shipment_id);

ALTER TABLE warehouse_stock
  ADD COLUMN IF NOT EXISTS shipment_id INT DEFAULT NULL COMMENT 'Owning shipment when stock represents a shipment',
  ADD COLUMN IF NOT EXISTS branch_id INT DEFAULT NULL COMMENT 'Branch that physically holds this stock',
  ADD KEY IF NOT EXISTS idx_ws_shipment (shipment_id),
  ADD KEY IF NOT EXISTS idx_ws_branch (branch_id);

-- cargo_manifest_items.shipment_id already exists in the base schema.


-- ----------------------------------------------------------------------------
-- 7. BACKFILL: one master shipment per existing package (legacy-safe).
--    Existing packages keep working; each gains a shipment identity derived
--    from its own data. Rows without a tenant are skipped.
-- ----------------------------------------------------------------------------
INSERT INTO shipments (tenant_id, shipment_number, tracking_number, customer_id,
                       sender_name, sender_phone, receiver_name, receiver_phone,
                       cargo_description, package_type, quantity, weight_kg,
                       length_cm, width_cm, height_cm, volume_cbm, declared_value,
                       current_status, current_branch_id, source_package_id,
                       created_by, created_at)
SELECT p.tenant_id,
       CONCAT('SHP-', LPAD(p.id, 5, '0')),
       p.tracking_number,
       p.customer_id,
       p.customer_name, p.customer_phone,
       p.customer_name, p.customer_phone,
       p.package_name, p.package_type, 1, COALESCE(p.weight_kg,0),
       COALESCE(p.length_cm,0), COALESCE(p.width_cm,0), COALESCE(p.height_cm,0),
       COALESCE(p.volume_cbm,0), COALESCE(p.declared_value,0),
       CASE p.status
           WHEN 'pending'   THEN 'REGISTERED'
           WHEN 'received'  THEN 'RECEIVED'
           WHEN 'warehouse' THEN 'IN_ORIGIN_WAREHOUSE'
           WHEN 'in_transit' THEN 'IN_TRANSIT'
           WHEN 'out_for_delivery' THEN 'OUT_FOR_DELIVERY'
           WHEN 'delivered' THEN 'DELIVERED'
           ELSE 'CANCELLED' END,
       p.current_branch_id,
       p.id,
       p.created_by,
       COALESCE(p.created_at, NOW())
FROM packages p
WHERE p.tenant_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM shipments s WHERE s.source_package_id = p.id);

UPDATE packages p
JOIN shipments s ON s.source_package_id = p.id
SET p.shipment_id = s.id
WHERE p.shipment_id IS NULL;

-- cargo_manifest_items: the legacy `shipment_id` column actually stores TRIP
-- ids (FK fk_cargo_trip -> trucking_trips.id; used by existing loading flows).
-- The true master-shipment link uses this NEW column instead:
ALTER TABLE cargo_manifest_items
  ADD COLUMN IF NOT EXISTS master_shipment_id INT DEFAULT NULL COMMENT 'Master shipments.id (legacy shipment_id stores trip ids)',
  ADD KEY IF NOT EXISTS idx_cmi_master_shipment (master_shipment_id);

ALTER TABLE trucking_trips
  ADD COLUMN IF NOT EXISTS approval_status ENUM('not_required','pending_approval','approved','rejected') DEFAULT 'not_required',
  ADD COLUMN IF NOT EXISTS approved_by INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL;

  PRIMARY KEY (id),
  KEY idx_srel_shipment (shipment_id),
  CONSTRAINT fk_srel_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  PRIMARY KEY (id),
  UNIQUE KEY uq_shipment_number (tenant_id, shipment_number),
  KEY idx_ship_tenant (tenant_id),
  KEY idx_ship_tracking (tracking_number),
  KEY idx_ship_customer (customer_id),
  KEY idx_ship_status (current_status),
  KEY idx_ship_dest_branch (destination_branch_id, current_status),
  KEY idx_ship_cur_branch (current_branch_id),
  CONSTRAINT fk_ship_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
