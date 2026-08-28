<?php
// superadmin/message_templates.php
// Maareynta Fariimaha (SMS/WhatsApp Templates).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    try {
        if ($action === 'get_templates') {
            $stmt = $pdo->query("SELECT id, template_key, template_name, message_content,
                                        template_type, status, created_at, updated_at
                                   FROM message_templates
                               ORDER BY id ASC");
            echo json_encode(['success' => true, 'templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($action === 'create_template') {
            $key     = trim((string)($_POST['template_key'] ?? ''));
            $name    = trim((string)($_POST['template_name'] ?? ''));
            $content = trim((string)($_POST['message_content'] ?? ''));
            $type    = trim((string)($_POST['template_type'] ?? 'whatsapp'));
            $statusIn = $_POST['status'] ?? 'active';
            $status   = in_array($statusIn, ['active','inactive'], true) ? $statusIn : 'active';

            if ($key === '' || $name === '' || $content === '') {
                echo json_encode(['success' => false, 'message' => 'Template key, name, and content are required.']);
                exit;
            }
            if (!preg_match('/^[A-Za-z0-9_.-]{2,100}$/', $key)) {
                echo json_encode(['success' => false, 'message' => 'Template key must be 2-100 chars: letters, digits, _ . -']);
                exit;
            }

            $dup = $pdo->prepare("SELECT id FROM message_templates WHERE template_key = ? LIMIT 1");
            $dup->execute([$key]);
            if ($dup->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'A template with this key already exists.']);
                exit;
            }

            $ins = $pdo->prepare("INSERT INTO message_templates
                (template_key, template_name, message_content, template_type, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            $ins->execute([$key, $name, $content, $type, $status]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Template created.']);
            exit;
        }

        if ($action === 'update_template') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim((string)($_POST['template_name'] ?? ''));
            $content = trim((string)($_POST['message_content'] ?? ''));
            $type    = trim((string)($_POST['template_type'] ?? 'whatsapp'));
            $statusIn = $_POST['status'] ?? 'active';
            $status   = in_array($statusIn, ['active','inactive'], true) ? $statusIn : 'active';

            if ($id <= 0 || $name === '' || $content === '') {
                echo json_encode(['success' => false, 'message' => 'ID, name, and content are required.']);
                exit;
            }
            $chk = $pdo->prepare("SELECT id FROM message_templates WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            if (!$chk->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Template not found.']);
                exit;
            }
            $upd = $pdo->prepare("UPDATE message_templates
                                     SET template_name = ?, message_content = ?, template_type = ?,
                                         status = ?, updated_at = NOW()
                                   WHERE id = ?");
            $upd->execute([$name, $content, $type, $status, $id]);
            echo json_encode(['success' => true, 'message' => 'Template updated.']);
            exit;
        }

        if ($action === 'delete_template') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid template id.']);
                exit;
            }
            $del = $pdo->prepare("DELETE FROM message_templates WHERE id = ?");
            $del->execute([$id]);
            if ($del->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Template not found.']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => 'Template deleted.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    } catch (Throwable $e) {
        error_log('[message_templates] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header" style="background: linear-gradient(135deg, #2D1859, #4B2C85); color: white; padding: 20px; border-radius: 12px; margin-bottom: 25px;">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="fas fa-envelope-open-text mr-2"></i> Maareynta Fariimaha (Templates)</h1>
                <p class="mb-0 text-white-50">Global notification / WhatsApp message templates.</p>
            </div>
            <button type="button" class="btn btn-light" id="btnNewTemplate">
                <i class="fas fa-plus"></i> New Template
            </button>
        </div>
    </div>

    <div class="row" id="templates-container">
        <div class="col-12 text-center p-5" id="templates-loading">
            <i class="fas fa-spinner fa-spin fa-3x" style="color: #2D1859;"></i>
        </div>
    </div>
</div>

<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: #2D1859; color: white;">
                <h5 class="modal-title" id="modalTitle">Template</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editTemplateId">
                <div class="alert alert-warning mb-3" style="font-size: 13px;">
                    Placeholders inside <code>{}</code> (e.g. <code>{customer_name}</code>) are replaced at send time.
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Template Key <span class="text-danger">*</span></label>
                        <input type="text" id="tplKey" class="form-control" placeholder="shipment_arrived" maxlength="100">
                        <small class="text-muted">Immutable identifier used in code. Cannot be changed after create.</small>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Template Name <span class="text-danger">*</span></label>
                        <input type="text" id="tplName" class="form-control" placeholder="Shipment Arrived" maxlength="150">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Channel</label>
                        <select id="tplType" class="form-control">
                            <option value="whatsapp">WhatsApp</option>
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Status</label>
                        <select id="tplStatus" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Message Body <span class="text-danger">*</span></label>
                    <textarea id="tplContent" class="form-control" rows="8" placeholder="Hi {customer_name}, your shipment {tracking_number} has arrived."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="saveTemplateBtn">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var CSRF = document.querySelector('meta[name="csrf-token"]');
    CSRF = CSRF ? CSRF.getAttribute('content') : '';

    function post(payload) {
        var body = new URLSearchParams(payload);
        if (CSRF) body.append('csrf_token', CSRF);
        return fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
            body: body,
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); });
    }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function renderEmpty(container) {
        container.innerHTML =
            '<div class="col-12"><div class="alert alert-info text-center p-4">' +
            '<i class="fas fa-inbox fa-2x mb-2 d-block"></i>' +
            '<strong>No templates yet.</strong><br>' +
            'Click <em>New Template</em> above to create your first message template.' +
            '</div></div>';
    }

    function renderError(container, msg) {
        container.innerHTML =
            '<div class="col-12"><div class="alert alert-danger">' +
            '<i class="fas fa-exclamation-triangle"></i> ' + esc(msg) +
            '</div></div>';
    }

    function renderList(container, templates) {
        if (!templates.length) return renderEmpty(container);
        var html = '';
        templates.forEach(function(t) {
            var badge = t.status === 'active'
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-secondary">Inactive</span>';
            html +=
                '<div class="col-md-6 mb-4">' +
                  '<div class="card shadow-sm h-100">' +
                    '<div class="card-header bg-light d-flex justify-content-between align-items-center">' +
                      '<div>' +
                        '<h5 class="mb-0" style="color: #2D1859; font-size: 16px;">' +
                          '<i class="fas fa-comment-dots mr-2"></i> ' + esc(t.template_name) +
                        '</h5>' +
                        '<small class="text-muted"><code>' + esc(t.template_key) + '</code> · ' +
                          esc(t.template_type || 'whatsapp') + ' · ' + badge + '</small>' +
                      '</div>' +
                      '<div>' +
                        '<button class="btn btn-sm btn-outline-primary edit-btn" data-id="' + t.id + '">' +
                          '<i class="fas fa-edit"></i></button> ' +
                        '<button class="btn btn-sm btn-outline-danger del-btn" data-id="' + t.id + '" data-name="' + esc(t.template_name) + '">' +
                          '<i class="fas fa-trash"></i></button>' +
                      '</div>' +
                    '</div>' +
                    '<div class="card-body">' +
                      '<div style="background: #f8f9fa; padding: 12px; border-radius: 8px; border-left: 4px solid #0F7A3A; white-space: pre-wrap; font-family: monospace; font-size: 13px;">' +
                        esc(t.message_content) +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                '</div>';
        });
        container.innerHTML = html;
    }

    var container = document.getElementById('templates-container');
    var templatesCache = [];

    function loadTemplates() {
        container.innerHTML =
            '<div class="col-12 text-center p-5"><i class="fas fa-spinner fa-spin fa-3x" style="color: #2D1859;"></i></div>';
        post({ ajax_action: 'get_templates' })
            .then(function(res) {
                if (!res.success) return renderError(container, res.message || 'Failed to load templates.');
                templatesCache = res.templates || [];
                renderList(container, templatesCache);
            })
            .catch(function(err) { renderError(container, 'Network error: ' + (err && err.message ? err.message : err)); });
    }

    function openModal(t) {
        var editing = !!t;
        document.getElementById('modalTitle').textContent = editing ? 'Edit Template' : 'New Template';
        document.getElementById('editTemplateId').value = editing ? t.id : '';
        document.getElementById('tplKey').value = editing ? t.template_key : '';
        document.getElementById('tplKey').readOnly = editing;
        document.getElementById('tplName').value = editing ? t.template_name : '';
        document.getElementById('tplType').value = editing ? (t.template_type || 'whatsapp') : 'whatsapp';
        document.getElementById('tplStatus').value = editing ? (t.status || 'active') : 'active';
        document.getElementById('tplContent').value = editing ? t.message_content : '';
        if (window.jQuery) window.jQuery('#templateModal').modal('show');
    }

    document.getElementById('btnNewTemplate').addEventListener('click', function() { openModal(null); });

    container.addEventListener('click', function(ev) {
        var editBtn = ev.target.closest('.edit-btn');
        if (editBtn) {
            var id = parseInt(editBtn.getAttribute('data-id'), 10);
            var t = templatesCache.find(function(x) { return parseInt(x.id, 10) === id; });
            if (t) openModal(t);
            return;
        }
        var delBtn = ev.target.closest('.del-btn');
        if (delBtn) {
            var id2 = parseInt(delBtn.getAttribute('data-id'), 10);
            var nm = delBtn.getAttribute('data-name') || '';
            if (!confirm('Delete template "' + nm + '"? This cannot be undone.')) return;
            post({ ajax_action: 'delete_template', id: id2 })
                .then(function(res) {
                    if (res.success) { loadTemplates(); }
                    else { alert('Delete failed: ' + (res.message || 'unknown error')); }
                })
                .catch(function(err) { alert('Network error: ' + err); });
        }
    });

    document.getElementById('saveTemplateBtn').addEventListener('click', function() {
        var id = document.getElementById('editTemplateId').value;
        var payload = {
            ajax_action: id ? 'update_template' : 'create_template',
            template_key: document.getElementById('tplKey').value.trim(),
            template_name: document.getElementById('tplName').value.trim(),
            template_type: document.getElementById('tplType').value,
            status: document.getElementById('tplStatus').value,
            message_content: document.getElementById('tplContent').value
        };
        if (id) payload.id = id;
        post(payload)
            .then(function(res) {
                if (res.success) {
                    if (window.jQuery) window.jQuery('#templateModal').modal('hide');
                    loadTemplates();
                } else {
                    alert(res.message || 'Save failed.');
                }
            })
            .catch(function(err) { alert('Network error: ' + err); });
    });

    loadTemplates();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
