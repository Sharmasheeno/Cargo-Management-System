<?php
// superadmin/message_templates.php
// Maareynta Fariimaha (SMS/WhatsApp Templates) -faras cargo

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is superadmin or company_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin', 'company_admin'])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION['role'];
$session_tenant_id = $_SESSION['tenant_id'] ?? 0;

require_once __DIR__ . '/../config/db_connect.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    if ($action === 'get_templates') {
        $stmt = $pdo->query("SELECT * FROM message_templates ORDER BY id ASC");
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'templates' => $templates]);
        exit;
    }

    if ($action === 'update_template') {
        $id = $_POST['id'] ?? 0;
        $content = $_POST['message_content'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE message_templates SET message_content = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$content, $id]);
            echo json_encode(['success' => true, 'message' => 'Fariinta waa la cusboonaysiiyay!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
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
                <p class="mb-0 text-white-50">Halkan waxaad ku beddeli kartaa qoraalada fariimaha loo diro macaamiisha (WhatsApp).</p>
            </div>
        </div>
    </div>

    <div class="row" id="templates-container">
        <!-- Loaded via AJAX -->
        <div class="col-12 text-center p-5">
            <i class="fas fa-spinner fa-spin fa-3x" style="color: #2D1859;"></i>
        </div>
    </div>
</div>

<!-- Edit Template Modal -->
<div class="modal fade" id="editTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: #2D1859; color: white;">
                <h5 class="modal-title">Wax Ka Beddel Fariinta: <span id="modalTemplateName"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editTemplateId">
                <div class="alert alert-warning mb-3">
                    <strong>Fiiro Gaar Ah:</strong> Ha beddelin ereyada ku dhex jira qaansooyinka <code>{}</code> sida <code>{customer_name}</code>, waayo nidaamka ayaa si otomaatik ah u gelinaya magaca rasmiga ah.
                </div>
                <div class="form-group">
                    <label>Qoraalka Fariinta</label>
                    <textarea id="editMessageContent" class="form-control" rows="8"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Jooji</button>
                <button type="button" class="btn btn-success" id="saveTemplateBtn">Keydi Isbeddelka</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    loadTemplates();

    function loadTemplates() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_templates' },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    let html = '';
                    res.templates.forEach(t => {
                        html += `
                        <div class="col-md-6 mb-4">
                            <div class="card shadow-sm h-100">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0" style="color: #2D1859; font-size: 16px;">
                                        <i class="fas fa-comment-dots mr-2"></i> ${t.template_name}
                                    </h5>
                                    <button class="btn btn-sm btn-outline-primary edit-btn" 
                                        data-id="${t.id}" 
                                        data-name="${t.template_name}"
                                        data-content="${escapeHtml(t.message_content)}">
                                        <i class="fas fa-edit"></i> Beddel
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #0F7A3A; font-family: monospace; white-space: pre-wrap;">${escapeHtml(t.message_content)}</div>
                                </div>
                            </div>
                        </div>`;
                    });
                    $('#templates-container').html(html);

                    $('.edit-btn').click(function() {
                        $('#editTemplateId').val($(this).data('id'));
                        $('#modalTemplateName').text($(this).data('name'));
                        $('#editMessageContent').val($(this).data('content'));
                        $('#editTemplateModal').modal('show');
                    });
                }
            }
        });
    }

    $('#saveTemplateBtn').click(function() {
        const id = $('#editTemplateId').val();
        const content = $('#editMessageContent').val();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'update_template', id: id, message_content: content },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#editTemplateModal').modal('hide');
                    loadTemplates();
                    alert(res.message);
                } else {
                    alert('Khalad: ' + res.message);
                }
            }
        });
    });

    function escapeHtml(unsafe) {
        if(!unsafe) return '';
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
