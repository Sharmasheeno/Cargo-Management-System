<?php
// tenant_admin/support_tickets.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Mogadishu');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant_admin') {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);

if (!$tenant_id) {
    header("Location: ../dashboard.php?error=no_tenant");
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            customer_id INT NOT NULL,
            ticket_number VARCHAR(50) NOT NULL UNIQUE,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            category VARCHAR(50) DEFAULT 'general',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            status ENUM('open', 'in_progress', 'waiting', 'resolved', 'closed') DEFAULT 'open',
            attachment_url VARCHAR(500),
            created_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at DATETIME,
            INDEX idx_customer_id (customer_id),
            INDEX idx_tenant_id (tenant_id),
            INDEX idx_status (status)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_ticket_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NOT NULL,
            user_type ENUM('customer', 'admin') DEFAULT 'customer',
            message TEXT NOT NULL,
            attachment_url VARCHAR(500),
            is_read BOOLEAN DEFAULT FALSE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket_id (ticket_id)
        )
    ");
} catch (PDOException $e) {
    die("Table error: " . htmlspecialchars($e->getMessage()));
}

function jsonResponse($data) {
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');

    try {
        $action = $_POST['ajax_action'];

        if ($action === 'get_tickets') {
            $status = $_POST['status'] ?? 'all';
            $search = trim($_POST['search'] ?? '');

            $where = "WHERE t.tenant_id = ?";
            $params = [$tenant_id];

            if ($status !== 'all') {
                $where .= " AND t.status = ?";
                $params[] = $status;
            }

            if ($search !== '') {
                $where .= " AND (
                    t.ticket_number LIKE ? OR 
                    t.subject LIKE ? OR 
                    t.message LIKE ? OR 
                    c.customer_name LIKE ? OR 
                    c.phone LIKE ?
                )";
                $like = "%$search%";
                array_push($params, $like, $like, $like, $like, $like);
            }

            $stmt = $pdo->prepare("
                SELECT 
                    t.*,
                    c.customer_name,
                    c.phone AS customer_phone,
                    c.email AS customer_email,
                    COUNT(r.id) AS reply_count,
                    MAX(r.created_at) AS last_reply_at
                FROM support_tickets t
                LEFT JOIN customers c ON c.id = t.customer_id
                LEFT JOIN support_ticket_replies r ON r.ticket_id = t.id
                $where
                GROUP BY t.id
                ORDER BY 
                    CASE t.priority
                        WHEN 'urgent' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        ELSE 4
                    END,
                    t.created_at DESC
            ");
            $stmt->execute($params);

            jsonResponse([
                'success' => true,
                'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
        }

        if ($action === 'get_ticket') {
            $ticket_id = (int)($_POST['ticket_id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT 
                    t.*,
                    c.customer_name,
                    c.phone AS customer_phone,
                    c.email AS customer_email
                FROM support_tickets t
                LEFT JOIN customers c ON c.id = t.customer_id
                WHERE t.id = ? AND t.tenant_id = ?
            ");
            $stmt->execute([$ticket_id, $tenant_id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket) {
                jsonResponse(['success' => false, 'message' => 'Ticket not found']);
            }

            $repliesStmt = $pdo->prepare("
                SELECT *
                FROM support_ticket_replies
                WHERE ticket_id = ?
                ORDER BY created_at ASC
            ");
            $repliesStmt->execute([$ticket_id]);

            jsonResponse([
                'success' => true,
                'ticket' => $ticket,
                'replies' => $repliesStmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
        }

        if ($action === 'add_reply') {
            $ticket_id = (int)($_POST['ticket_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');

            if ($message === '') {
                jsonResponse(['success' => false, 'message' => 'Reply message is required']);
            }

            $check = $pdo->prepare("
                SELECT id, status 
                FROM support_tickets 
                WHERE id = ? AND tenant_id = ?
            ");
            $check->execute([$ticket_id, $tenant_id]);
            $ticket = $check->fetch(PDO::FETCH_ASSOC);

            if (!$ticket) {
                jsonResponse(['success' => false, 'message' => 'Ticket not found']);
            }

            if (in_array($ticket['status'], ['resolved', 'closed'], true)) {
                jsonResponse(['success' => false, 'message' => 'This ticket is already closed or resolved']);
            }

            $stmt = $pdo->prepare("
                INSERT INTO support_ticket_replies 
                    (ticket_id, user_id, user_type, message, is_read, created_at)
                VALUES 
                    (?, ?, 'admin', ?, 0, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, $message]);

            $update = $pdo->prepare("
                UPDATE support_tickets
                SET status = 'waiting', updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $update->execute([$ticket_id, $tenant_id]);

            jsonResponse(['success' => true, 'message' => 'Reply sent successfully']);
        }

        if ($action === 'update_status') {
            $ticket_id = (int)($_POST['ticket_id'] ?? 0);
            $status = $_POST['status'] ?? '';

            $allowed = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];

            if (!in_array($status, $allowed, true)) {
                jsonResponse(['success' => false, 'message' => 'Invalid status']);
            }

            $resolvedSql = in_array($status, ['resolved', 'closed'], true)
                ? ", resolved_at = NOW()"
                : ", resolved_at = NULL";

            $stmt = $pdo->prepare("
                UPDATE support_tickets
                SET status = ?, updated_at = NOW() $resolvedSql
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$status, $ticket_id, $tenant_id]);

            jsonResponse(['success' => true, 'message' => 'Status updated successfully']);
        }

        jsonResponse(['success' => false, 'message' => 'Invalid action']);

    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Support Tickets</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --violet: #2D1859;
            --violet-dark: #1F0F3D;
            --yellow: #F5C410;
            --green: #16a34a;
            --red: #dc2626;
            --blue: #2563eb;
            --orange: #d97706;
            --gray-bg: #f3f4f6;
            --gray-line: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
        }

        body {
            background: var(--gray-bg);
            font-family: Arial, sans-serif;
            color: var(--text);
        }

        .container {
            max-width: 1350px;
            margin: 20px auto;
            padding: 15px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--violet), #4B2C85);
            color: white;
            padding: 22px;
            border-radius: 14px;
            margin-bottom: 20px;
        }

        .page-header h2 {
            margin: 0 0 6px 0;
        }

        .page-header p {
            margin: 0;
            opacity: .9;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 4px 14px rgba(0,0,0,.08);
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .toolbar-left h3 {
            margin: 0;
            color: var(--violet);
        }

        .toolbar-right {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        input, select, textarea, button {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 14px;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--violet);
            box-shadow: 0 0 0 3px rgba(82,0,102,.12);
        }

        button {
            background: var(--violet);
            color: white;
            cursor: pointer;
            border: none;
            font-weight: 700;
        }

        button:hover {
            background: var(--violet-dark);
        }

        button:disabled {
            opacity: .65;
            cursor: not-allowed;
        }

        .btn-danger {
            background: var(--red);
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th {
            background: #f9fafb;
            text-align: left;
            padding: 12px;
            color: #374151;
            font-size: 13px;
            border-bottom: 1px solid var(--gray-line);
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--gray-line);
            vertical-align: top;
            font-size: 14px;
        }

        tr:hover {
            background: #fafafa;
        }

        small {
            color: var(--muted);
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .open { background:#dbeafe; color:#1d4ed8; }
        .in_progress { background:#fef3c7; color:#92400e; }
        .waiting { background:#fde68a; color:#92400e; }
        .resolved { background:#dcfce7; color:#166534; }
        .closed { background:#e5e7eb; color:#374151; }

        .priority-low { color:#16a34a; font-weight:800; }
        .priority-medium { color:#2563eb; font-weight:800; }
        .priority-high { color:#d97706; font-weight:800; }
        .priority-urgent { color:#dc2626; font-weight:900; }

        .loadingBox,
        .emptyBox,
        .errorBox,
        .successBox {
            padding: 22px;
            border-radius: 12px;
            text-align: center;
            font-weight: 700;
        }

        .loadingBox { background:#f9fafb; color:var(--violet); }
        .emptyBox { background:#f9fafb; color:var(--muted); }
        .errorBox { background:#fee2e2; color:#b91c1c; }
        .successBox { background:#dcfce7; color:#166534; }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.50);
            z-index: 9999;
            padding: 25px;
            overflow: auto;
        }

        .modal-content {
            background: white;
            max-width: 950px;
            margin: auto;
            border-radius: 16px;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,.25);
        }

        .modal-header {
            background: var(--violet);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
        }

        .modal-header h3 {
            margin: 0;
        }

        .modal-body {
            padding: 20px;
        }

        .close {
            background: var(--red);
            padding: 7px 11px;
            border-radius: 8px;
        }

        .ticketSummary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            background: #f9fafb;
            padding: 14px;
            border-radius: 12px;
            margin-bottom: 15px;
        }

        .ticketSummary div {
            background: white;
            border: 1px solid #eee;
            padding: 11px;
            border-radius: 10px;
        }

        .ticketSummary strong {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 5px;
        }

        .ticketSummary span {
            font-weight: 800;
        }

        .subjectBox {
            background: linear-gradient(135deg, var(--violet), #4B2C85);
            color: white;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 16px;
        }

        .subjectBox h4 {
            margin: 0 0 5px 0;
        }

        .subjectBox small {
            color: white;
            opacity: .85;
        }

        .conversation {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 18px;
        }

        .msg {
            padding: 14px;
            border-radius: 14px;
            max-width: 86%;
            box-shadow: 0 1px 6px rgba(0,0,0,.08);
        }

        .msg.customer {
            background: #f3f4f6;
            border-left: 5px solid var(--violet);
            align-self: flex-start;
        }

        .msg.admin {
            background: #dcfce7;
            border-left: 5px solid var(--green);
            align-self: flex-end;
        }

        .msgHead {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .msgHead small {
            color: var(--muted);
        }

        .msgBody {
            line-height: 1.6;
        }

        .replyPanel {
            border-top: 1px solid var(--gray-line);
            padding-top: 16px;
        }

        .replyPanel textarea {
            width: 100%;
            min-height: 110px;
            resize: vertical;
            margin: 10px 0;
        }

        .replyActions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .statusPanel {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 18px;
            padding: 14px;
            background: #f9fafb;
            border-radius: 12px;
        }

        @media (max-width: 700px) {
            .modal {
                padding: 10px;
            }

            .msg {
                max-width: 100%;
            }

            .toolbar-right {
                width: 100%;
            }

            .toolbar-right input,
            .toolbar-right select {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <h2>Support Tickets</h2>
        <p>Manage customer support tickets, replies, and statuses. Timezone: East Africa Time.</p>
    </div>

    <div class="card">
        <div class="toolbar">
            <div class="toolbar-left">
                <h3>Tickets List</h3>
            </div>

            <div class="toolbar-right">
                <input type="text" id="searchInput" placeholder="Search ticket, customer, phone...">
                <select id="statusFilter">
                    <option value="all">All Tickets</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="waiting">Waiting Response</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
                <button onclick="loadTickets()">Refresh</button>
            </div>
        </div>

        <div id="ticketsTable">
            <div class="loadingBox">Loading tickets...</div>
        </div>
    </div>
</div>

<div class="modal" id="ticketModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Ticket Details</h3>
            <button class="close" onclick="closeModal()">X</button>
        </div>

        <div class="modal-body">
            <div id="ticketDetails"></div>

            <div class="statusPanel">
                <strong>Update Status:</strong>
                <select id="modalStatus">
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="waiting">Waiting Response</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
                <button id="statusBtn" onclick="updateStatus()">Update Status</button>
            </div>

            <div class="replyPanel">
                <h4>Send Reply</h4>
                <textarea id="replyMessage" placeholder="Write a professional reply to the customer..."></textarea>
                <div class="replyActions">
                    <button id="replyBtn" onclick="sendReply()">Send Reply</button>
                    <button class="btn-danger" onclick="closeModal()">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
let currentTicketId = null;
let searchTimer = null;

$(document).ready(function () {
    loadTickets();

    $('#statusFilter').on('change', function () {
        loadTickets();
    });

    $('#searchInput').on('keyup', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadTickets, 350);
    });

    $('#ticketModal').on('click', function(e) {
        if (e.target.id === 'ticketModal') closeModal();
    });
});

function loadTickets() {
    $('#ticketsTable').html('<div class="loadingBox">Loading tickets...</div>');

    $.ajax({
        url: window.location.href,
        type: 'POST',
        dataType: 'json',
        data: {
            ajax_action: 'get_tickets',
            status: $('#statusFilter').val(),
            search: $('#searchInput').val()
        },
        success: function (res) {
            if (!res.success) {
                $('#ticketsTable').html(`<div class="errorBox">${escapeHtml(res.message || 'Error loading tickets')}</div>`);
                return;
            }

            renderTickets(res.tickets);
        },
        error: function () {
            $('#ticketsTable').html('<div class="errorBox">Server error loading tickets</div>');
        }
    });
}

function renderTickets(tickets) {
    if (!tickets || tickets.length === 0) {
        $('#ticketsTable').html('<div class="emptyBox">No tickets found.</div>');
        return;
    }

    let html = `
        <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Customer</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Replies</th>
                    <th>Created</th>
                    <th>Last Update</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
    `;

    tickets.forEach(t => {
        html += `
            <tr>
                <td><strong>#${escapeHtml(t.ticket_number)}</strong></td>
                <td>
                    <strong>${escapeHtml(t.customer_name || 'Unknown')}</strong><br>
                    <small>${escapeHtml(t.customer_phone || '')}</small>
                </td>
                <td>
                    <strong>${escapeHtml(t.subject)}</strong><br>
                    <small>${escapeHtml(t.category)}</small>
                </td>
                <td><span class="priority-${escapeHtml(t.priority)}">${escapeHtml(t.priority)}</span></td>
                <td><span class="badge ${escapeHtml(t.status)}">${statusLabel(t.status)}</span></td>
                <td>${Number(t.reply_count || 0)}</td>
                <td>${formatEastAfricaTime(t.created_at)}</td>
                <td>${formatEastAfricaTime(t.updated_at)}</td>
                <td><button onclick="openTicket(${Number(t.id)})">View</button></td>
            </tr>
        `;
    });

    html += '</tbody></table></div>';
    $('#ticketsTable').html(html);
}

function openTicket(ticketId) {
    currentTicketId = ticketId;
    $('#ticketModal').show();
    $('#ticketDetails').html('<div class="loadingBox">Loading ticket...</div>');
    $('#replyMessage').val('');

    $.ajax({
        url: window.location.href,
        type: 'POST',
        dataType: 'json',
        data: {
            ajax_action: 'get_ticket',
            ticket_id: ticketId
        },
        success: function (res) {
            if (!res.success) {
                $('#ticketDetails').html(`<div class="errorBox">${escapeHtml(res.message || 'Ticket not found')}</div>`);
                return;
            }

            let t = res.ticket;
            $('#modalStatus').val(t.status);
            $('#modalTitle').text('Ticket #' + t.ticket_number);

            let html = `
                <div class="ticketSummary">
                    <div><strong>Customer</strong><span>${escapeHtml(t.customer_name || 'Unknown')}</span></div>
                    <div><strong>Phone</strong><span>${escapeHtml(t.customer_phone || '-')}</span></div>
                    <div><strong>Email</strong><span>${escapeHtml(t.customer_email || '-')}</span></div>
                    <div><strong>Status</strong><span class="badge ${escapeHtml(t.status)}">${statusLabel(t.status)}</span></div>
                    <div><strong>Priority</strong><span class="priority-${escapeHtml(t.priority)}">${escapeHtml(t.priority)}</span></div>
                    <div><strong>Created</strong><span>${formatEastAfricaTime(t.created_at)}</span></div>
                </div>

                <div class="subjectBox">
                    <h4>${escapeHtml(t.subject)}</h4>
                    <small>Category: ${escapeHtml(t.category)}</small>
                </div>

                <div class="conversation">
                    <div class="msg customer">
                        <div class="msgHead">
                            <strong>${escapeHtml(t.customer_name || 'Customer')}</strong>
                            <small>${formatEastAfricaTime(t.created_at)}</small>
                        </div>
                        <div class="msgBody">${escapeHtml(t.message).replace(/\n/g, '<br>')}</div>
                    </div>
            `;

            if (res.replies && res.replies.length > 0) {
                res.replies.forEach(r => {
                    let who = r.user_type === 'admin' ? 'Support Team' : (t.customer_name || 'Customer');

                    html += `
                        <div class="msg ${escapeHtml(r.user_type)}">
                            <div class="msgHead">
                                <strong>${escapeHtml(who)}</strong>
                                <small>${formatEastAfricaTime(r.created_at)}</small>
                            </div>
                            <div class="msgBody">${escapeHtml(r.message).replace(/\n/g, '<br>')}</div>
                        </div>
                    `;
                });
            }

            html += `</div>`;
            $('#ticketDetails').html(html);
        },
        error: function () {
            $('#ticketDetails').html('<div class="errorBox">Server error loading ticket</div>');
        }
    });
}

function sendReply() {
    let msg = $('#replyMessage').val().trim();

    if (!currentTicketId) {
        alert('No ticket selected');
        return;
    }

    if (!msg) {
        alert('Reply message is required');
        return;
    }

    const btn = $('#replyBtn');
    const oldText = btn.text();

    btn.prop('disabled', true).text('Sending...');

    $.ajax({
        url: window.location.href,
        type: 'POST',
        dataType: 'json',
        data: {
            ajax_action: 'add_reply',
            ticket_id: currentTicketId,
            message: msg
        },
        success: function (res) {
            if (res.success) {
                $('#replyMessage').val('');
                openTicket(currentTicketId);
                loadTickets();
            } else {
                alert(res.message || 'Reply failed');
            }
        },
        error: function () {
            alert('Server error sending reply');
        },
        complete: function () {
            btn.prop('disabled', false).text(oldText);
        }
    });
}

function updateStatus() {
    if (!currentTicketId) {
        alert('No ticket selected');
        return;
    }

    const btn = $('#statusBtn');
    const oldText = btn.text();

    btn.prop('disabled', true).text('Updating...');

    $.ajax({
        url: window.location.href,
        type: 'POST',
        dataType: 'json',
        data: {
            ajax_action: 'update_status',
            ticket_id: currentTicketId,
            status: $('#modalStatus').val()
        },
        success: function (res) {
            if (res.success) {
                openTicket(currentTicketId);
                loadTickets();
            } else {
                alert(res.message || 'Status update failed');
            }
        },
        error: function () {
            alert('Server error updating status');
        },
        complete: function () {
            btn.prop('disabled', false).text(oldText);
        }
    });
}

function closeModal() {
    $('#ticketModal').hide();
}

function statusLabel(status) {
    const labels = {
        open: 'Open',
        in_progress: 'In Progress',
        waiting: 'Waiting Response',
        resolved: 'Resolved',
        closed: 'Closed'
    };

    return labels[status] || status;
}

function formatEastAfricaTime(dateStr) {
    if (!dateStr) return '';

    let normalized = String(dateStr).replace(' ', 'T');
    let date = new Date(normalized);

    if (isNaN(date.getTime())) {
        return dateStr;
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Africa/Mogadishu',
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    }).format(date) + ' EAT';
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';

    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>