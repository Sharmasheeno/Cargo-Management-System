<?php
// staff/receptions.php
// Goods Reception log for Staff — records customer packages/shipments received at the branch
// (creates/updates rows in the `packages` table, scoped to the staff member's assigned branch)

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only staff accounts may access this page.
// See staffFamilyRoleTypes() in includes/functions.php.
require_once __DIR__ . '/../includes/functions.php';
$staff_role_types = staffFamilyRoleTypes();
$current_role_type = $_SESSION['role_type'] ?? $_SESSION['role'] ?? '';

if (!isset($_SESSION['user_id']) || !in_array($current_role_type, $staff_role_types, true)) {
    header("Location: ../login.php");
    exit;
}
if (!in_array($current_role_type, staffReceptionRoleTypes(), true)) {
    $_SESSION['flash_message'] = 'You do not have permission to access Receptions.';
    $_SESSION['flash_type'] = 'error';
    header("Location: ../staff/dashboard.php?error=access_denied");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if ($tenant_id <= 0) {
    header("Location: ../login.php?error=no_tenant");
    exit;
}

// ── Resolve the staff member's assigned branch ──────────────────────────────
$assigned_branch_id = null;

// Resolve this from authoritative assignment data for every request.  A
// session value may be stale after an administrator changes a staff member's
// branch, so it must not determine the origin of a newly received shipment.
try {
    $stmt = $pdo->prepare("
        SELECT uba.branch_id, uba.can_manage_branch
        FROM user_branch_assignments uba
        INNER JOIN branches b ON b.id = uba.branch_id
        WHERE uba.user_id = ? AND uba.is_primary = 1
          AND b.tenant_id = ? AND b.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$user_id, $tenant_id]);
    $branchAssign = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branchAssign) {
        $assigned_branch_id = (int)$branchAssign['branch_id'];
        $_SESSION['assigned_branch_id'] = $assigned_branch_id;
        $_SESSION['can_manage_branch'] = $branchAssign['can_manage_branch'];
    }
} catch (PDOException $e) {}

if (!$assigned_branch_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.default_branch_id
            FROM users u
            INNER JOIN branches b ON b.id = u.default_branch_id
            WHERE u.id = ? AND u.tenant_id = ?
              AND b.tenant_id = ? AND b.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$user_id, $tenant_id, $tenant_id]);
        $userBranch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userBranch && $userBranch['default_branch_id']) {
            $assigned_branch_id = $userBranch['default_branch_id'];
            $_SESSION['assigned_branch_id'] = $assigned_branch_id;
        }
    } catch (PDOException $e) {}
}

if (!$assigned_branch_id) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container-fluid"><div class="alert alert-danger m-4">You are not assigned to any branch. Please contact your administrator.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money2($value): string {
    return number_format((float)$value, 2, '.', '');
}

function json_response(array $data): void {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function generateReceptionTrackingNumber(PDO $pdo, int $branch_id): string {
    do {
        $num = 'REC-' . $branch_id . '-' . date('YmdHis') . '-' . random_int(100, 999);
        $chk = $pdo->prepare("SELECT id FROM packages WHERE tracking_number = ? LIMIT 1");
        $chk->execute([$num]);
    } while ($chk->fetch());
    return $num;
}

// Branch / tenant display names
$branch_name = 'My Branch';
try {
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$assigned_branch_id, $tenant_id]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($b) $branch_name = $b['branch_name'];
} catch (PDOException $e) {}

$tenant_name = 'Company';
try {
    $stmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($t) $tenant_name = $t['name'];
} catch (PDOException $e) {}

// Other branches this tenant can ship to (excludes the receptionist's own
// branch, which is always the implicit origin for a reception intake).
$destination_branches = [];
try {
    $stmt = $pdo->prepare("SELECT id, branch_name, branch_code FROM branches WHERE tenant_id = ? AND id != ? AND is_active = 1 ORDER BY branch_name ASC");
    $stmt->execute([$tenant_id, $assigned_branch_id]);
    $destination_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$quantity_units = ['cartons' => 'Cartons', 'boxes' => 'Boxes', 'bags' => 'Bags', 'pieces' => 'Pieces', 'pallets' => 'Pallets'];

$package_types = ['document' => 'Document', 'parcel' => 'Parcel', 'cargo' => 'Cargo', 'pallet' => 'Pallet', 'container' => 'Container'];
$status_labels = [
    'pending' => 'Pending',
    'received' => 'Received',
    'in_transit' => 'In Transit',
    'warehouse' => 'In Warehouse',
    'out_for_delivery' => 'Out for Delivery',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
];
$status_colors = [
    'pending' => '#F59E0B',
    'received' => '#3B82F6',
    'in_transit' => '#8B5CF6',
    'warehouse' => '#0EA5E9',
    'out_for_delivery' => '#F97316',
    'delivered' => '#10B981',
    'cancelled' => '#DC2626',
];

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    $action = $_POST['ajax_action'];

    try {
        if ($action === 'search_customers') {
            $q = trim($_POST['q'] ?? '');
            $results = [];
            if ($q !== '') {
                $like = '%' . $q . '%';
                $stmt = $pdo->prepare("
                    SELECT id, customer_name, phone, email
                    FROM customers
                    WHERE tenant_id = ? AND is_active = 1 AND (customer_name LIKE ? OR phone LIKE ?)
                    ORDER BY customer_name ASC
                    LIMIT 20
                ");
                $stmt->execute([$tenant_id, $like, $like]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            json_response(['success' => true, 'customers' => $results]);
        }

        if ($action === 'quick_add_customer') {
            $name = trim($_POST['customer_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($name === '') throw new Exception('Customer name is required');
            if ($phone === '') throw new Exception('Phone number is required');

            $chk = $pdo->prepare("SELECT id, customer_name, phone, email FROM customers WHERE tenant_id = ? AND phone = ? LIMIT 1");
            $chk->execute([$tenant_id, $phone]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                json_response(['success' => true, 'message' => 'Customer already exists', 'customer' => $existing]);
            }

            $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, branch_id, customer_name, phone, email, address, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$tenant_id, $assigned_branch_id, $name, $phone, $email, $address]);
            $id = (int)$pdo->lastInsertId();
            json_response(['success' => true, 'message' => 'Customer created successfully', 'customer' => ['id' => $id, 'customer_name' => $name, 'phone' => $phone, 'email' => $email]]);
        }

        if ($action === 'get_stats') {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_receptions,
                    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS today_count,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_count,
                    COUNT(CASE WHEN status = 'warehouse' THEN 1 END) AS warehouse_count,
                    COUNT(CASE WHEN status = 'delivered' THEN 1 END) AS delivered_count
                FROM packages
                WHERE tenant_id = ? AND current_branch_id = ? AND is_active = 1
            ");
            $stmt->execute([$tenant_id, $assigned_branch_id]);
            json_response(['success' => true, 'stats' => $stmt->fetch(PDO::FETCH_ASSOC)]);
        }

        if ($action === 'list_receptions') {
            $page = max(1, (int)($_POST['page'] ?? 1));
            $limit = 15;
            $offset = ($page - 1) * $limit;
            $search = trim($_POST['search'] ?? '');
            $status_filter = $_POST['status'] ?? '';
            $date_from = trim($_POST['date_from'] ?? '');
            $date_to = trim($_POST['date_to'] ?? '');

            $where = ["p.tenant_id = ?", "p.current_branch_id = ?", "p.is_active = 1"];
            $params = [$tenant_id, $assigned_branch_id];

            if ($search !== '') {
                $where[] = "(p.tracking_number LIKE ? OR p.package_name LIKE ? OR p.customer_name LIKE ? OR p.customer_phone LIKE ?)";
                $like = "%$search%";
                array_push($params, $like, $like, $like, $like);
            }
            if ($status_filter !== '' && array_key_exists($status_filter, $status_labels)) {
                $where[] = "p.status = ?";
                $params[] = $status_filter;
            }
            if ($date_from !== '') {
                $where[] = "DATE(p.created_at) >= ?";
                $params[] = $date_from;
            }
            if ($date_to !== '') {
                $where[] = "DATE(p.created_at) <= ?";
                $params[] = $date_to;
            }

            $where_clause = 'WHERE ' . implode(' AND ', $where);

            $count_stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM packages p $where_clause");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            $total_pages = max(1, (int)ceil($total / $limit));

            // Resolve the authoritative route from the linked master shipment
            // (branch IDs) rather than trusting only the legacy free-text
            // origin/destination columns on `packages`, which are optional
            // trade-lane notes and are usually left blank.
            $stmt = $pdo->prepare("
                SELECT p.*, ob.branch_name AS origin_branch_name, db.branch_name AS destination_branch_name
                FROM packages p
                LEFT JOIN shipments s ON s.id = p.shipment_id AND s.tenant_id = p.tenant_id
                LEFT JOIN branches ob ON ob.id = s.origin_branch_id AND ob.tenant_id = p.tenant_id
                LEFT JOIN branches db ON db.id = s.destination_branch_id AND db.tenant_id = p.tenant_id
                $where_clause
                ORDER BY p.created_at DESC, p.id DESC LIMIT $limit OFFSET $offset
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ob_start();
            ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover reception-table">
                    <thead>
                        <tr><th>Tracking #</th><th>Customer</th><th>Package</th><th>Type</th><th>Weight / CBM</th><th>Origin &rarr; Destination</th><th>Status</th><th>Received</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($rows): foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= h($r['tracking_number']) ?></strong></td>
                            <td><?= h($r['customer_name'] ?: '-') ?><?php if (!empty($r['customer_phone'])): ?><br><small class="text-muted"><?= h($r['customer_phone']) ?></small><?php endif; ?></td>
                            <td><?= h($r['package_name']) ?></td>
                            <td><?= h($package_types[$r['package_type']] ?? ucfirst($r['package_type'])) ?></td>
                            <td><?= money2($r['weight_kg']) ?> kg<br><small class="text-muted"><?= number_format((float)$r['volume_cbm'], 4) ?> CBM</small></td>
                            <td><?= h($r['origin_branch_name'] ?: ($r['origin'] ?: '—')) ?> &rarr; <?= h($r['destination_branch_name'] ?: ($r['destination'] ?: '—')) ?></td>
                            <td><span class="badge" style="background:<?= $status_colors[$r['status']] ?? '#6b7280' ?>22;color:<?= $status_colors[$r['status']] ?? '#6b7280' ?>;padding:5px 10px;border-radius:999px;font-weight:600;"><?= h($status_labels[$r['status']] ?? ucfirst($r['status'])) ?></span></td>
                            <td><?= $r['received_date'] ? h(date('Y-m-d', strtotime($r['received_date']))) : h(date('Y-m-d', strtotime($r['created_at']))) ?></td>
                            <td class="action-buttons">
                                <button class="btn btn-sm btn-info view-reception" data-id="<?= (int)$r['id'] ?>" title="View"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm btn-warning edit-reception" data-id="<?= (int)$r['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-sm btn-danger delete-reception" data-id="<?= (int)$r['id'] ?>" data-label="<?= h($r['tracking_number']) ?>" title="Delete"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-center py-5">
                            <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                            <p>No receptions recorded yet.</p>
                            <button class="btn btn-primary-custom" id="addReceptionBtnEmpty"><i class="fas fa-plus-circle"></i> Record Reception</button>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $table_html = ob_get_clean();

            ob_start();
            if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a data-page="<?= $page - 1 ?>" class="pagination-link"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?><span class="active-page"><?= $i ?></span>
                        <?php elseif ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?><a data-page="<?= $i ?>" class="pagination-link"><?= $i ?></a>
                        <?php elseif ($i == $page - 3 || $i == $page + 3): ?><span class="pagination-dots">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?><a data-page="<?= $page + 1 ?>" class="pagination-link">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
                </div>
            <?php endif;
            $pagination_html = ob_get_clean();

            json_response(['success' => true, 'table_html' => $table_html, 'pagination_html' => $pagination_html, 'total' => $total]);
        }

        if ($action === 'get_reception') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ? AND current_branch_id = ? LIMIT 1");
            $stmt->execute([$id, $tenant_id, $assigned_branch_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Reception record not found');
            json_response(['success' => true, 'reception' => $row]);
        }

        if ($action === 'save_reception') {
            $id = trim((string)($_POST['reception_id'] ?? ''));
            $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
            $customer_name = trim($_POST['customer_name'] ?? '');
            $customer_phone = trim($_POST['customer_phone'] ?? '');
            $customer_email = trim($_POST['customer_email'] ?? '');
            $package_name = trim($_POST['package_name'] ?? '');
            $package_type = $_POST['package_type'] ?? 'parcel';
            if (!array_key_exists($package_type, $package_types)) $package_type = 'parcel';
            $weight_kg = (float)($_POST['weight_kg'] ?? 0);
            $length_cm = (float)($_POST['length_cm'] ?? 0);
            $width_cm = (float)($_POST['width_cm'] ?? 0);
            $height_cm = (float)($_POST['height_cm'] ?? 0);
            $volume_cbm = (float)($_POST['volume_cbm'] ?? 0);
            $declared_value = (float)($_POST['declared_value'] ?? 0);
            $origin = trim($_POST['origin'] ?? '');
            $destination = trim($_POST['destination'] ?? '');
            $status = $_POST['status'] ?? 'received';
            if (!array_key_exists($status, $status_labels)) $status = 'received';
            $notes = trim($_POST['notes'] ?? '');

            // --- Master shipment fields (receiver identity, quantity, routing) ---
            $receiver_name = trim($_POST['receiver_name'] ?? '');
            $receiver_phone = trim($_POST['receiver_phone'] ?? '');
            $quantity = (int)($_POST['quantity'] ?? 0);
            $quantity_unit = $_POST['quantity_unit'] ?? '';
            if (!array_key_exists($quantity_unit, $quantity_units)) $quantity_unit = '';
            $delivery_method = ($_POST['delivery_method'] ?? 'branch_pickup') === 'door_delivery' ? 'door_delivery' : 'branch_pickup';
            $destination_branch_id = !empty($_POST['destination_branch_id']) ? (int)$_POST['destination_branch_id'] : null;

            if ($package_name === '') throw new Exception('Cargo description is required');
            if (!$customer_id && $customer_name === '') throw new Exception('Customer / sender is required');
            if ($receiver_name === '') throw new Exception('Receiver name is required');
            if ($receiver_phone === '') throw new Exception('Receiver phone is required');
            if ($quantity <= 0) throw new Exception('Quantity must be greater than zero');
            if ($weight_kg < 0) throw new Exception('Weight cannot be negative');

            // The origin branch is NEVER taken from client input (the visible
            // field is disabled/read-only and the hidden origin_branch_id
            // field is display-only, not trusted) - it is always the
            // receptionist's own session-assigned branch. This is the only
            // origin a Reception Clerk can ever create cargo from.
            $origin_branch_id = (int)$assigned_branch_id;

            // New receptions are inter-branch shipments: a destination branch is
            // required and must be validated server-side against the real
            // branches table - never trust a client-supplied id blindly, since
            // it could be tampered with (wrong tenant, inactive, nonexistent,
            // or the receptionist's own branch).
            if ($id === '') {
                if (!$destination_branch_id) throw new Exception('Destination branch is required');
                if ($destination_branch_id === $origin_branch_id) throw new Exception('Destination branch must be different from the origin branch');

                $destCheck = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1");
                $destCheck->execute([$destination_branch_id, $tenant_id]);
                if (!$destCheck->fetch()) throw new Exception('Selected destination branch is invalid or not available for this company');
            }

            // The `packages` table has no dedicated quantity/unit column (legacy
            // schema) - the authoritative value lives on the master shipment
            // (shipments.quantity). Record the human-readable quantity here too
            // so it's not lost from the reception's own notes/audit trail.
            $quantity_summary = $quantity . ($quantity_unit !== '' ? ' ' . $quantity_units[$quantity_unit] : ' unit(s)');
            $notes = trim('Quantity: ' . $quantity_summary . ($notes !== '' ? "\n" . $notes : ''));

            if ($volume_cbm <= 0 && $length_cm > 0 && $width_cm > 0 && $height_cm > 0) {
                $volume_cbm = ($length_cm * $width_cm * $height_cm) / 1000000;
            }

            // If an existing customer was selected, use their record's canonical name/phone/email
            if ($customer_id) {
                $c = $pdo->prepare("SELECT customer_name, phone, email FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1");
                $c->execute([$customer_id, $tenant_id]);
                $crow = $c->fetch(PDO::FETCH_ASSOC);
                if ($crow) {
                    $customer_name = $crow['customer_name'];
                    $customer_phone = $crow['phone'];
                    $customer_email = $crow['email'] ?? $customer_email;
                }
            }

            if ($id === '') {
                // Schema setup may issue DDL (which implicitly commits in
                // MySQL), so it must finish before the atomic package + master
                // shipment insert starts.
                require_once __DIR__ . '/../includes/shipment_functions.php';
                ensureShipmentSchema($pdo);
                $pdo->beginTransaction();
                $tracking_number = generateReceptionTrackingNumber($pdo, $assigned_branch_id);
                $received_date = in_array($status, ['received', 'in_transit', 'warehouse', 'out_for_delivery', 'delivered'], true) ? date('Y-m-d H:i:s') : null;
                $delivered_date = $status === 'delivered' ? date('Y-m-d H:i:s') : null;

                $stmt = $pdo->prepare("
                    INSERT INTO packages
                    (tenant_id, tracking_number, customer_id, customer_name, customer_phone, customer_email,
                     package_name, package_type, weight_kg, length_cm, width_cm, height_cm, volume_cbm,
                     declared_value, origin, destination, status, current_location, current_branch_id,
                     received_date, delivered_date, notes, created_by, created_at, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
                ");
                $stmt->execute([
                    $tenant_id, $tracking_number, $customer_id, $customer_name, $customer_phone, $customer_email,
                    $package_name, $package_type, $weight_kg, $length_cm, $width_cm, $height_cm, $volume_cbm,
                    $declared_value, $origin, $destination, $status, $branch_name, $assigned_branch_id,
                    $received_date, $delivered_date, $notes, $user_id
                ]);
                $new_package_id = (int)$pdo->lastInsertId();

                // --- Connected A→Z workflow: give every intake a master shipment
                // identity (SHP-xxxx + DEMO-MGQ-1001 style tracking) so reception,
                // warehouse, containers, trips and customer tracking all stay linked.
                $shipment_id = create_shipment_from_reception(
                    array_merge($_POST, [
                        'id' => $new_package_id,
                        'customer_id' => $customer_id ?: null,
                        'customer_name' => $customer_name,
                        'customer_phone' => $customer_phone,
                        'receiver_name' => $receiver_name,
                        'receiver_phone' => $receiver_phone,
                        'package_name' => $package_name,
                        'package_type' => $package_type,
                        'quantity' => $quantity,
                        'weight_kg' => $weight_kg,
                        'volume_cbm' => $volume_cbm,
                        'declared_value' => $declared_value,
                        'delivery_method' => $delivery_method,
                        'status' => $status,
                        'current_location' => $branch_name,
                    ]),
                    $tenant_id,
                    $origin_branch_id,
                    $destination_branch_id
                );
                if ($shipment_id <= 0) {
                    throw new Exception('Reception could not be linked to its master shipment');
                }

                $pdo->commit();

                json_response(['success' => true, 'message' => "Reception '$tracking_number' recorded successfully", 'id' => $new_package_id]);

            } else {
                $id = (int)$id;
                $existing = $pdo->prepare("SELECT status, received_date, delivered_date FROM packages WHERE id = ? AND tenant_id = ? AND current_branch_id = ? LIMIT 1");
                $existing->execute([$id, $tenant_id, $assigned_branch_id]);
                $old = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new Exception('Reception record not found');

                $received_date = $old['received_date'];
                if (!$received_date && in_array($status, ['received', 'in_transit', 'warehouse', 'out_for_delivery', 'delivered'], true)) {
                    $received_date = date('Y-m-d H:i:s');
                }
                $delivered_date = $old['delivered_date'];
                if ($status === 'delivered' && !$delivered_date) {
                    $delivered_date = date('Y-m-d H:i:s');
                }

                $stmt = $pdo->prepare("
                    UPDATE packages SET
                        customer_id = ?, customer_name = ?, customer_phone = ?, customer_email = ?,
                        package_name = ?, package_type = ?, weight_kg = ?, length_cm = ?, width_cm = ?, height_cm = ?,
                        volume_cbm = ?, declared_value = ?, origin = ?, destination = ?, status = ?,
                        received_date = ?, delivered_date = ?, notes = ?, updated_at = NOW()
                    WHERE id = ? AND tenant_id = ? AND current_branch_id = ?
                ");
                $stmt->execute([
                    $customer_id, $customer_name, $customer_phone, $customer_email,
                    $package_name, $package_type, $weight_kg, $length_cm, $width_cm, $height_cm,
                    $volume_cbm, $declared_value, $origin, $destination, $status,
                    $received_date, $delivered_date, $notes,
                    $id, $tenant_id, $assigned_branch_id
                ]);
                json_response(['success' => true, 'message' => 'Reception updated successfully', 'id' => $id]);
            }
        }

        if ($action === 'delete_reception') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT tracking_number FROM packages WHERE id = ? AND tenant_id = ? AND current_branch_id = ?");
            $stmt->execute([$id, $tenant_id, $assigned_branch_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Reception record not found');
            $del = $pdo->prepare("DELETE FROM packages WHERE id = ? AND tenant_id = ? AND current_branch_id = ?");
            $del->execute([$id, $tenant_id, $assigned_branch_id]);
            json_response(['success' => true, 'message' => "Reception '{$row['tracking_number']}' deleted"]);
        }

        json_response(['success' => false, 'message' => 'Unknown action']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="padding: 20px;">
<style>
    :root { --curdun-violet: #2D1859; --curdun-yellow: #F5C410; --curdun-violet-light: #4B2C85; --curdun-yellow-dark: #D4A70C; }
    .reception-page .page-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); border-radius: 16px; padding: 20px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    .reception-page .page-header h1 { color: #fff; font-size: 22px; margin: 0; font-weight: 700; }
    .reception-page .page-header h1 i { margin-right: 10px; }
    .reception-page .branch-badge { background: rgba(255,255,255,0.18); color: #fff; padding: 8px 16px; border-radius: 999px; font-size: 13px; }
    .reception-page .btn-primary-custom { background: var(--curdun-yellow); color: var(--curdun-violet); border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; }
    .reception-page .btn-primary-custom:hover { background: var(--curdun-yellow-dark); color: var(--curdun-violet); }
    .reception-page .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
    .reception-page .stat-card { background: #fff; border-radius: 12px; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
    .reception-page .stat-card h4 { font-size: 11px; color: #6c757d; margin: 0; text-transform: uppercase; letter-spacing: .03em; }
    .reception-page .stat-card .stat-number { font-size: 22px; font-weight: 800; color: var(--curdun-violet); }
    .reception-page .stat-icon { width: 44px; height: 44px; background: rgba(45,24,89,0.08); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
    .reception-page .stat-icon i { font-size: 18px; color: var(--curdun-violet); }
    .reception-page .filters-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; margin-bottom: 22px; }
    .reception-page .filter-form { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
    .reception-page .filter-group { flex: 1; min-width: 160px; }
    .reception-page .filter-group label { display: block; font-size: 12px; font-weight: 700; color: #4b5563; margin-bottom: 5px; }
    .reception-page .filter-group input, .reception-page .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 13px; }
    .reception-page .btn-filter { background: var(--curdun-violet); color: #fff; border: none; padding: 9px 20px; border-radius: 10px; cursor: pointer; }
    .reception-page .btn-reset { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 9px 20px; border-radius: 10px; cursor: pointer; margin-left: 8px; }
    .reception-page .table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; overflow: hidden; }
    .reception-page .reception-table th { background: #f9fafb; font-weight: 700; font-size: 12.5px; padding: 12px; white-space: nowrap; }
    .reception-page .reception-table td { padding: 12px; font-size: 13px; vertical-align: middle; }
    .reception-page .action-buttons { white-space: nowrap; }
    .reception-page .modal-header { background: linear-gradient(135deg, var(--curdun-violet), var(--curdun-violet-light)); color: #fff; border-bottom: none; }
    .reception-page .modal-header .close { color: #fff; opacity: .85; }
    /* Record Reception modal: fixed comfortable width, capped height, only
       the body scrolls - header and footer (Save/Cancel) always stay put.
       #receptionForm sits directly inside .modal-content as a sibling of
       .modal-header, and .modal-body/.modal-footer are children of THAT
       form, not of .modal-content - so the form itself must be included in
       the flex chain, or .modal-body's flex/overflow rules have no effect
       (its real parent, the form, was a plain block element). min-height:0
       is required on every link in the chain, or a flex item's default
       auto min-height stops it from ever shrinking enough to scroll. */
    #receptionModal .modal-dialog.modal-dialog-scrollable {
        width: min(1080px, 96vw);
        max-width: min(1080px, 96vw);
        max-height: 90vh;
    }
    #receptionModal .modal-content {
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        min-height: 0;
    }
    #receptionModal .modal-content > #receptionForm {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        overflow: hidden;
    }
    #receptionModal .modal-header { flex: 0 0 auto; }
    #receptionModal .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto !important;
        overflow-x: hidden;
        overscroll-behavior: contain;
        padding-right: 12px;
        padding-bottom: 20px;
    }
    #receptionModal .modal-body::-webkit-scrollbar { width: 8px; }
    #receptionModal .modal-body::-webkit-scrollbar-thumb { background: #c7c7c7; border-radius: 8px; }
    #receptionModal .modal-footer {
        flex: 0 0 auto;
        background: #fff;
        border-top: 1px solid #eee;
        z-index: 5;
    }

    /* Field layout: explicit CSS grid (not Bootstrap's row/col) so the
       breakpoints below are exact, independent of Bootstrap's own grid tiers. */
    .reception-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px 16px;
        margin-bottom: 14px;
    }
    .reception-grid .span-2 { grid-column: span 2; }
    .reception-grid .span-3 { grid-column: span 3; }
    @media (max-width: 900px) {
        .reception-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .reception-grid .span-2, .reception-grid .span-3 { grid-column: span 2; }
    }
    @media (max-width: 600px) {
        .reception-grid { grid-template-columns: minmax(0, 1fr); }
        .reception-grid .span-2, .reception-grid .span-3 { grid-column: span 1; }
    }
    .reception-page .form-group label { font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 5px; display: block; }
    .reception-page .form-control { border-radius: 10px; border: 1px solid #d1d5db; padding: 8px 12px; font-size: 13px; }
    .reception-page .customer-search-box { position: relative; }
    .reception-page .customer-search-results { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 1060; background: #fff; border: 1px solid #ddd; border-radius: 8px; max-height: 240px; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.12); }
    .reception-page .customer-result-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
    .reception-page .customer-result-item:hover { background: #f7f5ff; }
    .reception-page .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
    .reception-page .pagination-link, .reception-page .active-page { min-width: 40px; height: 40px; padding: 0 12px; border-radius: 10px; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 13px; font-weight: 600; cursor: pointer; }
    .reception-page .pagination-link { background: #fff; color: #374151; border: 1px solid #d1d5db; }
    .reception-page .pagination-link:hover { background: var(--curdun-violet); color: #fff; border-color: var(--curdun-violet); }
    .reception-page .active-page { background: var(--curdun-violet); color: #fff; border: 1px solid var(--curdun-violet); }
    @media (max-width: 768px) { .reception-page .filter-form { flex-direction: column; } .reception-page .stats-grid { grid-template-columns: repeat(2, 1fr); } }
</style>

<div class="reception-page">
    <div id="alert-placeholder"></div>

    <div class="page-header">
        <h1><i class="fas fa-clipboard-check"></i> Receptions</h1>
        <div class="d-flex flex-wrap align-items-center" style="gap:8px;">
            <span class="branch-badge"><i class="fas fa-code-branch"></i> <?= h($branch_name) ?></span>
            <button class="btn-primary-custom" id="addReceptionBtn"><i class="fas fa-plus-circle"></i> Record Reception</button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div><h4>Total Receptions</h4><div class="stat-number" id="stat-total">0</div></div><div class="stat-icon"><i class="fas fa-boxes-stacked"></i></div></div>
        <div class="stat-card"><div><h4>Today</h4><div class="stat-number" id="stat-today">0</div></div><div class="stat-icon"><i class="fas fa-calendar-day"></i></div></div>
        <div class="stat-card"><div><h4>Pending</h4><div class="stat-number" id="stat-pending">0</div></div><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div></div>
        <div class="stat-card"><div><h4>In Warehouse</h4><div class="stat-number" id="stat-warehouse">0</div></div><div class="stat-icon"><i class="fas fa-warehouse"></i></div></div>
        <div class="stat-card"><div><h4>Delivered</h4><div class="stat-number" id="stat-delivered">0</div></div><div class="stat-icon"><i class="fas fa-circle-check"></i></div></div>
    </div>

    <div class="filters-card">
        <div class="filter-form">
            <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" placeholder="Tracking #, customer, package..."></div>
            <div class="filter-group"><label>Status</label><select id="statusFilter"><option value="">All statuses</option><?php foreach ($status_labels as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label>From</label><input type="date" id="dateFrom"></div>
            <div class="filter-group"><label>To</label><input type="date" id="dateTo"></div>
            <div><button class="btn-filter" id="applyFilters"><i class="fas fa-filter"></i> Filter</button><button class="btn-reset" id="resetFilters"><i class="fas fa-undo"></i> Reset</button></div>
        </div>
    </div>

    <div class="table-card">
        <div id="reception-table-container"><div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Loading receptions...</p></div></div>
        <div id="pagination-container"></div>
    </div>
</div>

<!-- Add/Edit Reception Modal -->
<div class="modal fade" id="receptionModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="receptionModalLabel"><i class="fas fa-clipboard-check"></i> Record Reception</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
            <form id="receptionForm">
                <div class="modal-body">
                    <input type="hidden" name="reception_id" id="reception_id">

                    <!-- Customer / sender and receiver identity -->
                    <div class="reception-grid">
                        <div class="form-group customer-search-box">
                            <label>Customer / Sender <span class="text-danger">*</span> <button type="button" id="quickAddCustomerBtn" class="btn btn-sm btn-outline-primary">+ Add New</button></label>
                            <input type="hidden" name="customer_id" id="modalCustomerId">
                            <input type="text" id="modalCustomerSearch" class="form-control" autocomplete="off" placeholder="Search by name or phone...">
                            <div id="modalCustomerResults" class="customer-search-results"></div>
                            <small id="modalCustomerInfo" class="text-muted d-block mt-1">No customer selected.</small>
                        </div>
                        <div class="form-group">
                            <label>Receiver Name <span class="text-danger">*</span></label>
                            <input type="text" name="receiver_name" id="modalReceiverName" class="form-control" placeholder="e.g. Maxamed" required>
                        </div>
                        <div class="form-group">
                            <label>Receiver Phone <span class="text-danger">*</span></label>
                            <input type="text" name="receiver_phone" id="modalReceiverPhone" class="form-control" placeholder="e.g. 631111111" required>
                        </div>
                    </div>

                    <!-- Cargo details -->
                    <div class="reception-grid">
                        <div class="form-group span-2">
                            <label>Cargo Description <span class="text-danger">*</span></label>
                            <input type="text" name="package_name" id="modalPackageName" class="form-control" placeholder="e.g. Clothes">
                        </div>
                        <div class="form-group">
                            <label>Package Type</label>
                            <select name="package_type" id="modalPackageType" class="form-control">
                                <?php foreach ($package_types as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Quantity <span class="text-danger">*</span></label>
                            <input type="number" step="1" min="1" name="quantity" id="modalQuantity" class="form-control" value="1" required>
                        </div>
                        <div class="form-group">
                            <label>Quantity Unit</label>
                            <select name="quantity_unit" id="modalQuantityUnit" class="form-control">
                                <?php foreach ($quantity_units as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Weight (kg)</label>
                            <input type="number" step="0.01" min="0" name="weight_kg" id="modalWeight" class="form-control" value="0">
                        </div>
                    </div>

                    <!-- Routing -->
                    <div class="reception-grid">
                        <div class="form-group">
                            <label>Origin Branch</label>
                            <input type="text" class="form-control" value="<?= h($branch_name) ?>" disabled>
                            <!-- Disabled inputs are never submitted by the browser - carry the
                                 same value via a hidden field so it's visible in the POST payload.
                                 NOT trusted server-side: the handler always re-derives the real
                                 origin from $assigned_branch_id (session), never from this field,
                                 so tampering with it client-side cannot spoof another branch. -->
                            <input type="hidden" name="origin_branch_id" value="<?= (int)$assigned_branch_id ?>">
                            <small class="text-muted">Always your own branch.</small>
                        </div>
                        <div class="form-group">
                            <label>Destination Branch <span class="text-danger">*</span></label>
                            <select name="destination_branch_id" id="modalDestinationBranch" class="form-control" required>
                                <option value="">Select destination branch...</option>
                                <?php foreach ($destination_branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['branch_name']) ?><?= !empty($b['branch_code']) ? ' (' . h($b['branch_code']) . ')' : '' ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Delivery Method</label>
                            <select name="delivery_method" id="modalDeliveryMethod" class="form-control">
                                <option value="branch_pickup" selected>Branch Pickup</option>
                                <option value="door_delivery">Door Delivery</option>
                            </select>
                        </div>
                    </div>

                    <!-- Advanced / internal fields -->
                    <div class="reception-grid">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="modalStatus" class="form-control">
                                <?php foreach ($status_labels as $k => $v): ?><option value="<?= h($k) ?>" <?= $k === 'received' ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Length (cm)</label><input type="number" step="0.1" min="0" name="length_cm" id="modalLength" class="form-control dimension-input" value="0"></div>
                        <div class="form-group"><label>Width (cm)</label><input type="number" step="0.1" min="0" name="width_cm" id="modalWidth" class="form-control dimension-input" value="0"></div>
                        <div class="form-group"><label>Height (cm)</label><input type="number" step="0.1" min="0" name="height_cm" id="modalHeight" class="form-control dimension-input" value="0"></div>
                        <div class="form-group"><label>Volume (CBM)</label><input type="number" step="0.0001" min="0" name="volume_cbm" id="modalVolume" class="form-control" value="0" placeholder="Auto or manual"></div>
                        <div class="form-group"><label>Declared Value ($)</label><input type="number" step="0.01" min="0" name="declared_value" id="modalDeclaredValue" class="form-control" value="0"></div>
                    </div>
                    <div class="reception-grid">
                        <div class="form-group span-2"><label>Origin (trade lane, optional)</label><input type="text" name="origin" id="modalOrigin" class="form-control" placeholder="e.g. Guangzhou, China" list="originSuggestions"><datalist id="originSuggestions"><option value="China (Yiwu)"><option value="China (Guangzhou)"><option value="Dubai"><option value="Local"></datalist></div>
                        <div class="form-group"><label>Destination (free text, optional)</label><input type="text" name="destination" id="modalDestination" class="form-control" placeholder="e.g. Mogadishu"></div>
                    </div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" id="modalNotes" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn-primary-custom">Save Reception</button></div>
            </form>
        </div>
    </div>
</div>

<!-- View Reception Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> Reception Details</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body" id="viewModalBody"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div></div></div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header" style="background:#dc2626;color:#fff;"><h5 class="modal-title">Delete Reception</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div><div class="modal-body">Delete reception <strong id="deleteLabel"></strong>? This cannot be undone.</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button></div></div></div>
</div>

<!-- Quick Add Customer Modal -->
<div class="modal fade" id="quickCustomerModal" tabindex="-1">
    <div class="modal-dialog"><form class="modal-content" id="quickCustomerForm"><div class="modal-header"><h5 class="modal-title">Add New Customer</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><div class="form-group"><label>Customer Name <span class="text-danger">*</span></label><input type="text" name="customer_name" id="qcName" class="form-control" required></div><div class="form-group"><label>Phone <span class="text-danger">*</span></label><input type="text" name="phone" id="qcPhone" class="form-control" required placeholder="+252..."></div><div class="form-group"><label>Email</label><input type="email" name="email" id="qcEmail" class="form-control"></div><div class="form-group"><label>Address</label><input type="text" name="address" id="qcAddress" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn-primary-custom">Save Customer</button></div></form></div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    let currentPage = 1;
    let deleteId = null;
    let customerSearchTimer = null;
    const STATUS_LABELS = <?= json_encode($status_labels) ?>;
    const PACKAGE_TYPES = <?= json_encode($package_types) ?>;

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return String(text).replace(/[&<>"']/g, function(m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
        });
    }

    function showAlert(type, msg) {
        const cls = type === 'success' ? 'alert-success' : 'alert-danger';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#alert-placeholder').html('<div class="alert ' + cls + ' alert-dismissible fade show" style="position:fixed;top:20px;right:20px;z-index:9999;min-width:300px;border-radius:12px;"><i class="fas ' + icon + '"></i> ' + escapeHtml(msg) + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
        setTimeout(function() { $('.alert').fadeOut(400, function() { $(this).remove(); }); }, 4500);
    }

    function selectCustomer(c) {
        $('#modalCustomerId').val(c.id || '');
        $('#modalCustomerSearch').val(c.customer_name ? c.customer_name + (c.phone ? ' (' + c.phone + ')' : '') : '');
        $('#modalCustomerInfo').text(c.id ? 'Selected: ' + c.customer_name : 'No customer selected.');
        $('#modalCustomerResults').hide().empty();
    }

    $('#modalCustomerSearch').on('input', function() {
        const q = $(this).val().trim();
        $('#modalCustomerId').val('');
        clearTimeout(customerSearchTimer);
        if (q.length < 2) { $('#modalCustomerResults').hide().empty(); return; }
        customerSearchTimer = setTimeout(function() {
            $.post(window.location.href, { ajax_action: 'search_customers', q: q }, function(res) {
                const box = $('#modalCustomerResults');
                box.empty();
                if (!res.customers || res.customers.length === 0) {
                    box.html('<div class="customer-result-item">No customers found.<br><button type="button" class="btn btn-sm btn-primary mt-1" id="openQuickAddFromSearch">+ Add Customer</button></div>').show();
                    return;
                }
                res.customers.forEach(function(c) {
                    box.append('<div class="customer-result-item" data-id="' + c.id + '" data-name="' + escapeHtml(c.customer_name) + '" data-phone="' + escapeHtml(c.phone || '') + '"><strong>' + escapeHtml(c.customer_name) + '</strong><br><small>' + escapeHtml(c.phone || '-') + '</small></div>');
                });
                box.show();
            }, 'json');
        }, 300);
    });

    $(document).on('click', '.customer-result-item', function() {
        selectCustomer({ id: $(this).data('id'), customer_name: $(this).data('name'), phone: $(this).data('phone') });
    });
    $(document).on('click', '#openQuickAddFromSearch', function() { $('#quickCustomerModal').modal('show'); });
    $(document).on('click', function(e) { if (!$(e.target).closest('.customer-search-box').length) $('#modalCustomerResults').hide(); });

    function calculateCBM() {
        const l = parseFloat($('#modalLength').val()) || 0;
        const w = parseFloat($('#modalWidth').val()) || 0;
        const h = parseFloat($('#modalHeight').val()) || 0;
        if (l > 0 && w > 0 && h > 0) $('#modalVolume').val(((l * w * h) / 1000000).toFixed(6));
    }
    $('.dimension-input').on('input', calculateCBM);

    function loadStats() {
        $.post(window.location.href, { ajax_action: 'get_stats' }, function(res) {
            if (!res.success) return;
            $('#stat-total').text(res.stats.total_receptions || 0);
            $('#stat-today').text(res.stats.today_count || 0);
            $('#stat-pending').text(res.stats.pending_count || 0);
            $('#stat-warehouse').text(res.stats.warehouse_count || 0);
            $('#stat-delivered').text(res.stats.delivered_count || 0);
        }, 'json');
    }

    function loadReceptions() {
        $.post(window.location.href, {
            ajax_action: 'list_receptions',
            page: currentPage,
            search: $('#searchInput').val(),
            status: $('#statusFilter').val(),
            date_from: $('#dateFrom').val(),
            date_to: $('#dateTo').val()
        }, function(res) {
            if (res.success) {
                $('#reception-table-container').html(res.table_html);
                $('#pagination-container').html(res.pagination_html);
                bindTableEvents();
            } else {
                $('#reception-table-container').html('<div class="alert alert-danger m-3">' + escapeHtml(res.message) + '</div>');
            }
        }, 'json').fail(function() {
            $('#reception-table-container').html('<div class="alert alert-danger m-3">Failed to load receptions.</div>');
        });
    }

    function bindTableEvents() {
        $('.pagination-link').off('click').on('click', function() { currentPage = Number($(this).data('page')) || 1; loadReceptions(); });

        $('.view-reception').off('click').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, { ajax_action: 'get_reception', id: id }, function(res) {
                if (!res.success) { showAlert('error', res.message); return; }
                const r = res.reception;
                let html = '<div class="row">';
                html += '<div class="col-5"><strong>Tracking #:</strong></div><div class="col-7">' + escapeHtml(r.tracking_number) + '</div>';
                html += '<div class="col-5"><strong>Customer:</strong></div><div class="col-7">' + escapeHtml(r.customer_name || '-') + (r.customer_phone ? ' (' + escapeHtml(r.customer_phone) + ')' : '') + '</div>';
                html += '<div class="col-5"><strong>Package:</strong></div><div class="col-7">' + escapeHtml(r.package_name) + '</div>';
                html += '<div class="col-5"><strong>Type:</strong></div><div class="col-7">' + escapeHtml(PACKAGE_TYPES[r.package_type] || r.package_type) + '</div>';
                html += '<div class="col-5"><strong>Weight:</strong></div><div class="col-7">' + Number(r.weight_kg || 0).toFixed(2) + ' kg</div>';
                html += '<div class="col-5"><strong>Volume:</strong></div><div class="col-7">' + Number(r.volume_cbm || 0).toFixed(4) + ' CBM</div>';
                html += '<div class="col-5"><strong>Declared Value:</strong></div><div class="col-7">$' + Number(r.declared_value || 0).toFixed(2) + '</div>';
                html += '<div class="col-5"><strong>Origin &rarr; Destination:</strong></div><div class="col-7">' + escapeHtml(r.origin || '-') + ' &rarr; ' + escapeHtml(r.destination || '-') + '</div>';
                html += '<div class="col-5"><strong>Status:</strong></div><div class="col-7">' + escapeHtml(STATUS_LABELS[r.status] || r.status) + '</div>';
                html += '<div class="col-5"><strong>Received:</strong></div><div class="col-7">' + escapeHtml(r.received_date || '-') + '</div>';
                if (r.notes) html += '<div class="col-5"><strong>Notes:</strong></div><div class="col-7">' + escapeHtml(r.notes) + '</div>';
                html += '</div>';
                $('#viewModalBody').html(html);
                $('#viewModal').modal('show');
            }, 'json');
        });

        $('.edit-reception').off('click').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, { ajax_action: 'get_reception', id: id }, function(res) {
                if (!res.success) { showAlert('error', res.message); return; }
                const r = res.reception;
                $('#receptionModalLabel').text('Edit Reception');
                $('#reception_id').val(r.id);
                selectCustomer({ id: r.customer_id || '', customer_name: r.customer_name || '', phone: r.customer_phone || '' });
                $('#modalPackageName').val(r.package_name);
                $('#modalPackageType').val(r.package_type);
                $('#modalStatus').val(r.status);
                $('#modalWeight').val(r.weight_kg);
                $('#modalLength').val(r.length_cm);
                $('#modalWidth').val(r.width_cm);
                $('#modalHeight').val(r.height_cm);
                $('#modalVolume').val(r.volume_cbm);
                $('#modalDeclaredValue').val(r.declared_value);
                $('#modalOrigin').val(r.origin);
                $('#modalDestination').val(r.destination);
                $('#modalNotes').val(r.notes);
                $('#receptionModal').modal('show');
            }, 'json');
        });

        $('.delete-reception').off('click').on('click', function() {
            deleteId = $(this).data('id');
            $('#deleteLabel').text($(this).data('label'));
            $('#deleteModal').modal('show');
        });
    }

    $('#confirmDeleteBtn').on('click', function() {
        if (!deleteId) return;
        $.post(window.location.href, { ajax_action: 'delete_reception', id: deleteId }, function(res) {
            $('#deleteModal').modal('hide');
            showAlert(res.success ? 'success' : 'error', res.message);
            if (res.success) { loadReceptions(); loadStats(); }
            deleteId = null;
        }, 'json');
    });

    function resetReceptionForm() {
        $('#receptionForm')[0].reset();
        $('#reception_id').val('');
        selectCustomer({});
        $('#modalWeight, #modalLength, #modalWidth, #modalHeight, #modalVolume, #modalDeclaredValue').val(0);
        $('#modalStatus').val('received');
        $('#receptionModalLabel').text('Record Reception');
    }

    $('#addReceptionBtn, #addReceptionBtnEmpty').on('click', function() { resetReceptionForm(); $('#receptionModal').modal('show'); });
    $(document).on('click', '#addReceptionBtnEmpty', function() { resetReceptionForm(); $('#receptionModal').modal('show'); });

    $('#receptionForm').on('submit', function(e) {
        e.preventDefault();
        const isEdit = !!$('#reception_id').val();
        if (!$('#modalCustomerId').val()) { showAlert('error', 'Please select a customer / sender'); return; }
        if (!$('#modalReceiverName').val().trim()) { showAlert('error', 'Receiver name is required'); return; }
        if (!$('#modalReceiverPhone').val().trim()) { showAlert('error', 'Receiver phone is required'); return; }
        if (!$('#modalPackageName').val().trim()) { showAlert('error', 'Cargo description is required'); return; }
        if (!(parseInt($('#modalQuantity').val(), 10) > 0)) { showAlert('error', 'Quantity must be greater than zero'); return; }
        if (!isEdit && !$('#modalDestinationBranch').val()) { showAlert('error', 'Please select a destination branch'); return; }
        const fd = new FormData(this);
        fd.append('ajax_action', 'save_reception');
        $.ajax({
            url: window.location.href, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if (res.success) { $('#receptionModal').modal('hide'); loadReceptions(); loadStats(); }
            },
            error: function() { showAlert('error', 'Server error while saving reception'); }
        });
    });

    $('#quickAddCustomerBtn').on('click', function() { $('#quickCustomerForm')[0].reset(); $('#quickCustomerModal').modal('show'); });
    $('#quickCustomerForm').on('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('ajax_action', 'quick_add_customer');
        $.ajax({
            url: window.location.href, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(res) {
                showAlert(res.success ? 'success' : 'error', res.message);
                if (res.success && res.customer) { selectCustomer(res.customer); $('#quickCustomerModal').modal('hide'); }
            },
            error: function() { showAlert('error', 'Server error'); }
        });
    });

    $('#applyFilters').on('click', function() { currentPage = 1; loadReceptions(); });
    $('#resetFilters').on('click', function() { $('#searchInput,#dateFrom,#dateTo').val(''); $('#statusFilter').val(''); currentPage = 1; loadReceptions(); });
    $('#searchInput').on('keypress', function(e) { if (e.which === 13) { currentPage = 1; loadReceptions(); } });

    loadReceptions();
    loadStats();
});
</script>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
