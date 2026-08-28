<?php
// customer/support.php - Customer Support & Help Center
// Provides support tickets, FAQs, and WhatsApp integration
// Primary: Violet #520066, Secondary: Yellow #f4dd08

require_once __DIR__ . '/_auth.php';

// WhatsApp Support Number - Fixed for this customer support
$WHATSAPP_SUPPORT_NUMBER = "252614417875";
$WEBSITE_PORTFOLIO = "https://curdunict.com/";

// Get customer information
$customer_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT c.*, t.name as tenant_name, t.logo_url, t.address as tenant_address, t.phone as tenant_phone, t.email as tenant_email
        FROM customers c
        LEFT JOIN tenants t ON c.tenant_id = t.id
        WHERE c.id = ?
    ");
    $stmt->execute([$customer_id]);
    $customer_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customer_info = null;
}

$tenant_name = $customer_info['tenant_name'] ?? 'Cargo Management System';
$customer_name = $customer_info['customer_name'] ?? 'Customer';
$customer_phone = $customer_info['phone'] ?? '';
$customer_email = $customer_info['email'] ?? '';

// Create support_tickets table if not exists
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
            INDEX idx_status (status),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )
    ");
    
    // Create replies table
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
            INDEX idx_ticket_id (ticket_id),
            FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
        )
    ");
    
    // Create faqs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_faqs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT,
            question VARCHAR(500) NOT NULL,
            answer TEXT NOT NULL,
            category VARCHAR(50) DEFAULT 'general',
            display_order INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant_id (tenant_id),
            INDEX idx_is_active (is_active)
        )
    ");
} catch (PDOException $e) {
    // Tables might already exist
}

// Insert default FAQs if none exist
try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM support_faqs WHERE tenant_id = ? OR tenant_id IS NULL");
    $check->execute([$session_tenant_id]);
    if ($check->fetchColumn() == 0) {
        $defaultFaqs = [
            ['How long does shipping take?', 'Shipping times vary by origin. China shipments typically take 25-35 days, while Dubai shipments take 10-15 days.', 'shipping'],
            ['How can I track my package?', 'You can track your packages in the "My Packages" section. Each package has a unique tracking number and real-time status updates.', 'tracking'],
            ['What payment methods do you accept?', 'We accept Cash, Bank Transfer, Check, and Mobile Money. You can make payments through the Payments section.', 'payments'],
            ['How do I contact support?', 'You can contact us via WhatsApp at +252614417875 or email info@curdun.com. You can also create a support ticket below.', 'contact'],
            ['What is your refund policy?', 'Refunds are processed within 5-7 business days after approval. Please contact support for refund requests.', 'policy'],
            ['How do I check my debt balance?', 'Your current debt balance is displayed in the dashboard. You can also view it in the Payments section.', 'account'],
            ['Can I change my delivery address?', 'Yes, please create a support ticket with your new address and we will update it within 24 hours.', 'delivery'],
            ['What are your storage fees?', 'Storage fees are calculated based on volume (CBM/FT) and duration. Contact support for a detailed quote.', 'pricing']
        ];
        
        $insert = $pdo->prepare("INSERT INTO support_faqs (tenant_id, question, answer, category, display_order) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaultFaqs as $index => $faq) {
            $insert->execute([$session_tenant_id, $faq[0], $faq[1], $faq[2], $index]);
        }
    }
} catch (PDOException $e) {
    // Default FAQs might already exist
}

// ============================================
// AJAX HANDLERS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once __DIR__ . '/../includes/csrf.php';
    require_csrf_token();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    try {
        switch ($action) {
            case 'get_tickets':
                handleGetTickets($pdo, $customer_id, $session_tenant_id);
                break;
            case 'get_ticket':
                handleGetTicket($pdo, $customer_id, $session_tenant_id);
                break;
            case 'create_ticket':
                handleCreateTicket($pdo, $customer_id, $session_tenant_id, $user_id);
                break;
            case 'add_reply':
                handleAddReply($pdo, $customer_id, $session_tenant_id, $user_id);
                break;
            case 'close_ticket':
                handleCloseTicket($pdo, $customer_id, $session_tenant_id);
                break;
            case 'get_faqs':
                handleGetFaqs($pdo, $session_tenant_id);
                break;
            case 'get_stats':
                handleGetSupportStats($pdo, $customer_id, $session_tenant_id);
                break;
            case 'send_whatsapp_inquiry':
                handleSendWhatsAppInquiry($pdo, $customer_id, $session_tenant_id);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        error_log("Support error: " . $e->getMessage());
    }
    exit;
}

// ============================================
// AJAX HANDLER FUNCTIONS
// ============================================

function handleGetTickets($pdo, $customer_id, $session_tenant_id) {
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $status = $_POST['status'] ?? 'all';
    
    $where_conditions = ["customer_id = ?", "tenant_id = ?"];
    $params = [$customer_id, $session_tenant_id];
    
    if ($status !== 'all') {
        $where_conditions[] = "status = ?";
        $params[] = $status;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    $count_sql = "SELECT COUNT(*) as total FROM support_tickets $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_tickets / $limit);
    
    $sql = "
        SELECT t.*, 
               COUNT(r.id) as reply_count,
               MAX(r.created_at) as last_reply_at
        FROM support_tickets t
        LEFT JOIN support_ticket_replies r ON t.id = r.ticket_id
        $where_clause
        GROUP BY t.id
        ORDER BY t.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'tickets' => $tickets,
        'total_pages' => $total_pages,
        'current_page' => $page
    ]);
}

function handleGetTicket($pdo, $customer_id, $session_tenant_id) {
    $ticket_id = $_POST['ticket_id'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT t.*, 
               COUNT(r.id) as reply_count
        FROM support_tickets t
        LEFT JOIN support_ticket_replies r ON t.id = r.ticket_id
        WHERE t.id = ? AND t.customer_id = ? AND t.tenant_id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$ticket_id, $customer_id, $session_tenant_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        exit;
    }
    
    // Get replies
    $replyStmt = $pdo->prepare("
        SELECT r.*, 
               CASE 
                   WHEN r.user_type = 'customer' THEN ? 
                   ELSE 'Support Team' 
               END as user_name
        FROM support_ticket_replies r
        WHERE r.ticket_id = ?
        ORDER BY r.created_at ASC
    ");
    $replyStmt->execute([$customer_id, $ticket_id]);
    $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark unread replies as read
    $updateStmt = $pdo->prepare("
        UPDATE support_ticket_replies 
        SET is_read = TRUE 
        WHERE ticket_id = ? AND user_type = 'admin' AND is_read = FALSE
    ");
    $updateStmt->execute([$ticket_id]);
    
    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'replies' => $replies
    ]);
}

function handleCreateTicket($pdo, $customer_id, $session_tenant_id, $user_id) {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $category = $_POST['category'] ?? 'general';
    $priority = $_POST['priority'] ?? 'medium';
    
    if (empty($subject)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a subject']);
        exit;
    }
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your message']);
        exit;
    }
    
    // Generate ticket number
    $ticket_number = 'TKT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Check for unique ticket number
    $check = $pdo->prepare("SELECT id FROM support_tickets WHERE ticket_number = ?");
    $check->execute([$ticket_number]);
    if ($check->fetch()) {
        $ticket_number = 'TKT-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO support_tickets (tenant_id, customer_id, ticket_number, subject, message, category, priority, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$session_tenant_id, $customer_id, $ticket_number, $subject, $message, $category, $priority, $user_id]);
    
    $ticket_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Support ticket created successfully!',
        'ticket_id' => $ticket_id,
        'ticket_number' => $ticket_number
    ]);
}

function handleAddReply($pdo, $customer_id, $session_tenant_id, $user_id) {
    $ticket_id = $_POST['ticket_id'] ?? 0;
    $message = trim($_POST['message'] ?? '');
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your reply']);
        exit;
    }
    
    // Verify ticket belongs to customer
    $verify = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id = ? AND customer_id = ? AND tenant_id = ?");
    $verify->execute([$ticket_id, $customer_id, $session_tenant_id]);
    $ticket = $verify->fetch();
    
    if (!$ticket) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        exit;
    }
    
    if ($ticket['status'] === 'closed' || $ticket['status'] === 'resolved') {
        echo json_encode(['success' => false, 'message' => 'This ticket is closed. Please create a new ticket.']);
        exit;
    }
    
    // Add reply
    $stmt = $pdo->prepare("
        INSERT INTO support_ticket_replies (ticket_id, user_id, user_type, message, created_at)
        VALUES (?, ?, 'customer', ?, NOW())
    ");
    $stmt->execute([$ticket_id, $user_id, $message]);
    
    // Update ticket status to waiting (for admin response)
    $update = $pdo->prepare("
        UPDATE support_tickets 
        SET status = 'waiting', updated_at = NOW()
        WHERE id = ? AND status != 'closed'
    ");
    $update->execute([$ticket_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Reply added successfully'
    ]);
}

function handleCloseTicket($pdo, $customer_id, $session_tenant_id) {
    $ticket_id = $_POST['ticket_id'] ?? 0;
    
    $verify = $pdo->prepare("SELECT id FROM support_tickets WHERE id = ? AND customer_id = ? AND tenant_id = ? AND status NOT IN ('closed', 'resolved')");
    $verify->execute([$ticket_id, $customer_id, $session_tenant_id]);
    
    if (!$verify->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found or already closed']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        UPDATE support_tickets 
        SET status = 'closed', resolved_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$ticket_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Ticket closed successfully'
    ]);
}

function handleGetFaqs($pdo, $session_tenant_id) {
    $category = $_POST['category'] ?? 'all';
    
    $where_conditions = ["is_active = 1"];
    $params = [];
    
    if ($category !== 'all') {
        $where_conditions[] = "category = ?";
        $params[] = $category;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    $sql = "
        SELECT * FROM support_faqs 
        $where_clause
        ORDER BY display_order ASC, id ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'faqs' => $faqs
    ]);
}

function handleGetSupportStats($pdo, $customer_id, $session_tenant_id) {
    // Get ticket stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tickets,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
            SUM(CASE WHEN priority = 'high' OR priority = 'urgent' THEN 1 ELSE 0 END) as high_priority
        FROM support_tickets
        WHERE customer_id = ? AND tenant_id = ?
    ");
    $stmt->execute([$customer_id, $session_tenant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent tickets
    $recentStmt = $pdo->prepare("
        SELECT id, ticket_number, subject, status, priority, created_at
        FROM support_tickets
        WHERE customer_id = ? AND tenant_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentStmt->execute([$customer_id, $session_tenant_id]);
    $recent_tickets = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'recent_tickets' => $recent_tickets
    ]);
}

function handleSendWhatsAppInquiry($pdo, $customer_id, $session_tenant_id) {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your message']);
        exit;
    }
    
    // Log the inquiry
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO support_whatsapp_log (customer_id, subject, message, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $logStmt->execute([$customer_id, $subject, $message]);
    } catch (Exception $e) {
        // Log table might not exist, continue
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'WhatsApp inquiry prepared'
    ]);
}

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Support Center - <?= htmlspecialchars($tenant_name) ?> | Cargo Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f0f2f5;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1a1a2e;
            line-height: 1.5;
        }
        
        :root {
            --violet: #2D1859;
            --violet-dark: #1F0F3D;
            --violet-light: #4B2C85;
            --violet-soft: #f3e8f7;
            --yellow: #F5C410;
            --yellow-dark: #D4A70C;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --whatsapp: #25D366;
            --whatsapp-light: #dcf8c5;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
        }
        
        /* Container */
        .support-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        @media (min-width: 768px) {
            .support-container {
                padding: 1.5rem;
            }
        }
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--violet) 0%, var(--violet-light) 100%);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }
        
        .welcome-banner h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .welcome-banner h1 i {
            font-size: 2rem;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }
        
        /* Quick Contact Bar */
        .quick-contact {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .contact-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.2s ease;
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .contact-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }
        
        .contact-btn.whatsapp {
            background: var(--whatsapp);
            border-color: var(--whatsapp);
        }
        
        .contact-btn.whatsapp:hover {
            background: #128C7E;
        }
        
        .contact-btn.website {
            background: var(--info);
            border-color: var(--info);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-100);
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-card .stat-info h4 {
            font-size: 0.7rem;
            color: var(--gray-500);
            margin: 0 0 0.25rem 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-card .stat-info .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--violet);
        }
        
        .stat-card .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            background: var(--violet-soft);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-card .stat-icon i {
            font-size: 1.25rem;
            color: var(--violet);
        }
        
        /* Two Column Layout */
        .support-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        
        @media (min-width: 992px) {
            .support-layout {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        /* Cards */
        .support-card {
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-100);
            overflow: hidden;
        }
        
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--gray-100);
            background: var(--gray-50);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--violet);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        /* FAQ Items */
        .faq-item {
            border-bottom: 1px solid var(--gray-100);
            padding: 1rem 0;
        }
        
        .faq-item:last-child {
            border-bottom: none;
        }
        
        .faq-question {
            font-weight: 600;
            color: var(--gray-800);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.25rem 0;
        }
        
        .faq-question:hover {
            color: var(--violet);
        }
        
        .faq-question i {
            transition: transform 0.2s ease;
        }
        
        .faq-answer {
            display: none;
            padding-top: 0.75rem;
            color: var(--gray-600);
            font-size: 0.875rem;
            line-height: 1.6;
        }
        
        .faq-answer.active {
            display: block;
        }
        
        /* Ticket List */
        .ticket-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .ticket-item {
            border-bottom: 1px solid var(--gray-100);
            padding: 1rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        
        .ticket-item:hover {
            background: var(--gray-50);
        }
        
        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .ticket-number {
            font-weight: 700;
            color: var(--violet);
            font-size: 0.875rem;
        }
        
        .ticket-status {
            padding: 0.25rem 0.625rem;
            border-radius: 2rem;
            font-size: 0.6875rem;
            font-weight: 600;
        }
        
        .status-open {
            background: var(--info-light);
            color: var(--info);
        }
        
        .status-in_progress {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .status-waiting {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .status-resolved {
            background: var(--success-light);
            color: var(--success);
        }
        
        .status-closed {
            background: var(--gray-200);
            color: var(--gray-600);
        }
        
        .ticket-subject {
            font-weight: 500;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
        }
        
        .ticket-date {
            font-size: 0.6875rem;
            color: var(--gray-400);
        }
        
        .priority-badge {
            display: inline-block;
            padding: 0.1875rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.625rem;
            font-weight: 600;
        }
        
        .priority-low {
            background: var(--success-light);
            color: var(--success);
        }
        
        .priority-medium {
            background: var(--info-light);
            color: var(--info);
        }
        
        .priority-high {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .priority-urgent {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 0.25rem;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-family: inherit;
            transition: all 0.2s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--violet);
            box-shadow: 0 0 0 3px rgba(82, 0, 102, 0.1);
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .btn-primary {
            background: var(--violet);
            color: white;
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            background: var(--violet-dark);
            transform: translateY(-1px);
        }
        
        .btn-whatsapp {
            background: var(--whatsapp);
            color: white;
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-whatsapp:hover {
            background: #128C7E;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-secondary:hover {
            background: var(--gray-200);
        }
        
        /* Modal Styles */
        .modal-header {
            background: linear-gradient(135deg, var(--violet), var(--violet-light));
            color: white;
            border-radius: var(--radius-md) var(--radius-md) 0 0;
            padding: 1rem 1.25rem;
        }
        
        .modal-header .close {
            color: white;
            opacity: 1;
        }
        
        .modal-header .close:hover {
            color: var(--yellow);
        }
        
        .reply-item {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }
        
        .reply-customer {
            background: var(--gray-50);
            border-left: 3px solid var(--violet);
        }
        
        .reply-admin {
            background: var(--info-light);
            border-left: 3px solid var(--info);
        }
        
        .reply-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .reply-message {
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--gray-700);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray-400);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Alert */
        .alert {
            position: fixed;
            top: 1rem;
            right: 1rem;
            left: 1rem;
            z-index: 9999;
            padding: 0.875rem 1rem;
            border-radius: var(--radius);
            animation: slideIn 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: var(--shadow-lg);
        }
        
        @media (min-width: 768px) {
            .alert {
                left: auto;
                min-width: 320px;
            }
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .alert-success {
            background: var(--success-light);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: var(--danger-light);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .alert-info {
            background: var(--info-light);
            color: var(--info);
            border-left: 4px solid var(--info);
        }
        
        .loading-spinner {
            text-align: center;
            padding: 2rem;
        }
        
        .loading-spinner i {
            font-size: 1.5rem;
            color: var(--violet);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Category Filter */
        .category-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .category-btn {
            padding: 0.375rem 0.875rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
            background: var(--gray-100);
            color: var(--gray-600);
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .category-btn.active {
            background: var(--violet);
            color: white;
        }
        
        .category-btn:hover {
            background: var(--violet-light);
            color: white;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>

<div class="support-container">
    <div id="alert-placeholder"></div>
    
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <h1>
            <i class="fas fa-headset"></i>
            Support Center
        </h1>
        <p>We're here to help you 24/7. Choose how you'd like to get support.</p>
        <div class="quick-contact">
            <a href="#" class="contact-btn whatsapp" id="whatsappSupportBtn">
                <i class="fab fa-whatsapp"></i> WhatsApp Support
            </a>
            <a href="<?= $WEBSITE_PORTFOLIO ?>" target="_blank" class="contact-btn website">
                <i class="fas fa-globe"></i> Visit Our Website
            </a>
            <a href="mailto:info@curdun.com" class="contact-btn">
                <i class="fas fa-envelope"></i> Email Support
            </a>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="stats-grid" id="statsContainer">
        <div class="stat-card">
            <div class="stat-info">
                <h4>Total Tickets</h4>
                <div class="stat-number" id="stat-total">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-ticket-alt"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Open Tickets</h4>
                <div class="stat-number" id="stat-open">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-envelope-open"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>In Progress</h4>
                <div class="stat-number" id="stat-progress">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-spinner"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Waiting Response</h4>
                <div class="stat-number" id="stat-waiting">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>Resolved</h4>
                <div class="stat-number" id="stat-resolved">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h4>High Priority</h4>
                <div class="stat-number" id="stat-high">0</div>
            </div>
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
    </div>
    
    <!-- Two Column Layout -->
    <div class="support-layout">
        <!-- Left Column: Create Ticket & WhatsApp -->
        <div class="support-card">
            <div class="card-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Ticket</h3>
            </div>
            <div class="card-body">
                <form id="ticketForm">
                    <div class="form-group">
                        <label>Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" id="ticketSubject" placeholder="Brief description of your issue" required>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="ticketCategory">
                            <option value="general">General Inquiry</option>
                            <option value="shipping">Shipping & Delivery</option>
                            <option value="payment">Payment Issue</option>
                            <option value="package">Package Issue</option>
                            <option value="account">Account Problem</option>
                            <option value="complaint">Complaint</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" id="ticketPriority">
                            <option value="low">Low - General question</option>
                            <option value="medium" selected>Medium - Need help</option>
                            <option value="high">High - Urgent issue</option>
                            <option value="urgent">Urgent - Critical problem</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Message <span class="text-danger">*</span></label>
                        <textarea name="message" id="ticketMessage" placeholder="Please describe your issue in detail..."></textarea>
                    </div>
                    <button type="submit" class="btn-primary" id="submitTicketBtn">
                        <i class="fas fa-paper-plane"></i> Submit Ticket
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Right Column: WhatsApp Quick Chat -->
        <div class="support-card">
            <div class="card-header">
                <h3><i class="fab fa-whatsapp"></i> WhatsApp Support</h3>
                <span class="priority-badge priority-low">Response within minutes</span>
            </div>
            <div class="card-body">
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <i class="fab fa-whatsapp" style="font-size: 4rem; color: var(--whatsapp);"></i>
                    <h4 style="margin: 0.5rem 0;">Chat with us on WhatsApp</h4>
                    <p class="text-muted" style="font-size: 0.875rem;">Get instant support from our team</p>
                </div>
                
                <form id="whatsappForm">
                    <div class="form-group">
                        <label>Subject (Optional)</label>
                        <input type="text" id="whatsappSubject" placeholder="E.g., Package inquiry, Payment issue...">
                    </div>
                    <div class="form-group">
                        <label>Your Message <span class="text-danger">*</span></label>
                        <textarea id="whatsappMessage" placeholder="Type your message here..."></textarea>
                    </div>
                    <button type="submit" class="btn-whatsapp" id="sendWhatsappBtn" style="width: 100%;">
                        <i class="fab fa-whatsapp"></i> Send via WhatsApp
                    </button>
                </form>
                
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200); text-align: center;">
                    <p class="text-muted" style="font-size: 0.75rem;">
                        <i class="fas fa-clock"></i> Available 24/7 | 
                        <i class="fas fa-shield-alt"></i> Your messages are secure
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- My Tickets Section -->
    <div class="support-card" style="margin-top: 1.5rem;">
        <div class="card-header">
            <h3><i class="fas fa-ticket-alt"></i> My Support Tickets</h3>
            <div>
                <select id="ticketStatusFilter" class="btn-secondary" style="padding: 0.375rem 0.875rem;">
                    <option value="all">All Tickets</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="waiting">Waiting</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <div id="ticketsList" class="ticket-list">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i> Loading tickets...
                </div>
            </div>
            <div id="ticketsPagination" style="padding: 1rem; text-align: center;"></div>
        </div>
    </div>
</div>

<!-- Ticket Details Modal -->
<div class="modal fade" id="ticketModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: var(--radius-md);">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-ticket-alt"></i> Ticket Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="ticketModalBody">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn-primary" id="addReplyBtn">Add Reply</button>
                <button type="button" class="btn-secondary" id="closeTicketBtn" style="background: var(--danger); color: white;">Close Ticket</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Reply Modal -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: var(--radius-md);">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-reply"></i> Add Reply</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="replyForm">
                <div class="modal-body">
                    <input type="hidden" name="ticket_id" id="replyTicketId">
                    <div class="form-group">
                        <label>Your Message <span class="text-danger">*</span></label>
                        <textarea name="message" id="replyMessage" class="form-control" rows="5" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary">Send Reply</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- FAQ Modal -->
<div class="modal fade" id="faqModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: var(--radius-md);">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-question-circle"></i> Frequently Asked Questions</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="faqModalBody">
                <div class="category-filters" id="faqCategories">
                    <button class="category-btn active" data-category="all">All</button>
                    <button class="category-btn" data-category="shipping">Shipping</button>
                    <button class="category-btn" data-category="tracking">Tracking</button>
                    <button class="category-btn" data-category="payments">Payments</button>
                    <button class="category-btn" data-category="account">Account</button>
                    <button class="category-btn" data-category="policy">Policy</button>
                </div>
                <div id="faqList">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i> Loading FAQs...
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn-primary" id="createFromFaqBtn">Create Ticket</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    let currentPage = 1;
    let currentTicketId = null;
    let currentTicketNumber = null;
    let customerName = '<?= htmlspecialchars($customer_name) ?>';
    let customerPhone = '<?= htmlspecialchars($customer_phone) ?>';
    let whatsappNumber = '<?= $WHATSAPP_SUPPORT_NUMBER ?>';
    let websitePortfolio = '<?= $WEBSITE_PORTFOLIO ?>';
    
    // Format phone number for WhatsApp
    function formatWhatsAppNumber(phone) {
        let cleaned = phone.toString().replace(/\D/g, '');
        if (cleaned.startsWith('0')) {
            cleaned = '252' + cleaned.substring(1);
        }
        if (!cleaned.startsWith('252') && cleaned.length === 9) {
            cleaned = '252' + cleaned;
        }
        return cleaned;
    }
    
    // Load tickets
    function loadTickets() {
        const status = $('#ticketStatusFilter').val();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_tickets',
                page: currentPage,
                status: status
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderTickets(response.tickets);
                    renderPagination(response.total_pages, response.current_page);
                } else {
                    $('#ticketsList').html('<div class="empty-state"><i class="fas fa-inbox"></i><p>No tickets found</p></div>');
                }
            },
            error: function() {
                $('#ticketsList').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading tickets</p></div>');
            }
        });
    }
    
    function renderTickets(tickets) {
        if (!tickets || tickets.length === 0) {
            $('#ticketsList').html('<div class="empty-state"><i class="fas fa-inbox"></i><p>No tickets found</p><button class="btn-primary" id="createFirstTicketBtn" style="margin-top: 1rem;"><i class="fas fa-plus"></i> Create Your First Ticket</button></div>');
            $('#createFirstTicketBtn').off('click').on('click', function() {
                $('#ticketSubject').focus();
                $('html, body').animate({ scrollTop: 0 }, 300);
            });
            return;
        }
        
        let html = '';
        tickets.forEach(function(ticket) {
            const statusClass = getStatusClass(ticket.status);
            const statusText = getStatusText(ticket.status);
            const priorityClass = getPriorityClass(ticket.priority);
            const date = new Date(ticket.created_at).toLocaleDateString();
            
            html += `
                <div class="ticket-item" data-id="${ticket.id}" data-number="${ticket.ticket_number}">
                    <div class="ticket-header">
                        <span class="ticket-number">#${ticket.ticket_number}</span>
                        <span class="ticket-status ${statusClass}">${statusText}</span>
                        <span class="priority-badge ${priorityClass}">${ticket.priority.toUpperCase()}</span>
                    </div>
                    <div class="ticket-subject">${escapeHtml(ticket.subject)}</div>
                    <div class="ticket-date">
                        <i class="far fa-calendar-alt"></i> ${date}
                        ${ticket.reply_count > 0 ? ` | <i class="fas fa-reply"></i> ${ticket.reply_count} replies` : ''}
                    </div>
                </div>
            `;
        });
        
        $('#ticketsList').html(html);
        
        $('.ticket-item').off('click').on('click', function() {
            const id = $(this).data('id');
            const number = $(this).data('number');
            viewTicket(id, number);
        });
    }
    
    function renderPagination(totalPages, currentPage) {
        if (totalPages <= 1) {
            $('#ticketsPagination').empty();
            return;
        }
        
        let html = '<div class="pagination" style="justify-content: center; margin-top: 1rem;">';
        if (currentPage > 1) {
            html += `<a data-page="${currentPage - 1}"><i class="fas fa-chevron-left"></i> Previous</a>`;
        }
        for (let i = 1; i <= totalPages; i++) {
            if (i === currentPage) {
                html += `<span class="active">${i}</span>`;
            } else {
                html += `<a data-page="${i}">${i}</a>`;
            }
        }
        if (currentPage < totalPages) {
            html += `<a data-page="${currentPage + 1}">Next <i class="fas fa-chevron-right"></i></a>`;
        }
        html += '</div>';
        
        $('#ticketsPagination').html(html);
        
        $('.pagination a').off('click').on('click', function(e) {
            e.preventDefault();
            currentPage = $(this).data('page');
            loadTickets();
        });
    }
    
    function viewTicket(id, number) {
        currentTicketId = id;
        currentTicketNumber = number;
        
        $('#ticketModalBody').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading ticket...</div>');
        $('#ticketModal').modal('show');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_ticket',
                ticket_id: id
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderTicketDetails(response.ticket, response.replies);
                } else {
                    $('#ticketModalBody').html('<div class="alert alert-error">' + escapeHtml(response.message) + '</div>');
                }
            },
            error: function() {
                $('#ticketModalBody').html('<div class="alert alert-error">Error loading ticket details</div>');
            }
        });
    }
    
    function renderTicketDetails(ticket, replies) {
        const statusClass = getStatusClass(ticket.status);
        const statusText = getStatusText(ticket.status);
        const priorityClass = getPriorityClass(ticket.priority);
        const createdDate = new Date(ticket.created_at).toLocaleString();
        
        let repliesHtml = '';
        if (replies && replies.length > 0) {
            replies.forEach(function(reply) {
                const replyClass = reply.user_type === 'customer' ? 'reply-customer' : 'reply-admin';
                const replyDate = new Date(reply.created_at).toLocaleString();
                repliesHtml += `
                    <div class="reply-item ${replyClass}">
                        <div class="reply-header">
                            <span><strong>${escapeHtml(reply.user_name)}</strong> (${reply.user_type === 'customer' ? 'You' : 'Support Team'})</span>
                            <span>${replyDate}</span>
                        </div>
                        <div class="reply-message">${escapeHtml(reply.message).replace(/\n/g, '<br>')}</div>
                    </div>
                `;
            });
        } else {
            repliesHtml = '<div class="empty-state"><i class="fas fa-comment"></i><p>No replies yet</p></div>';
        }
        
        const canClose = ticket.status !== 'closed' && ticket.status !== 'resolved';
        
        $('#ticketModalBody').html(`
            <div style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem;">
                    <div><strong>Ticket #:</strong> ${escapeHtml(ticket.ticket_number)}</div>
                    <div><strong>Status:</strong> <span class="ticket-status ${statusClass}">${statusText}</span></div>
                    <div><strong>Priority:</strong> <span class="priority-badge ${priorityClass}">${ticket.priority.toUpperCase()}</span></div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <div><strong>Subject:</strong> ${escapeHtml(ticket.subject)}</div>
                    <div><strong>Category:</strong> ${escapeHtml(ticket.category)}</div>
                    <div><strong>Created:</strong> ${createdDate}</div>
                </div>
                <div class="reply-item reply-customer" style="background: var(--violet-soft);">
                    <div class="reply-header">
                        <span><strong>Original Message</strong></span>
                        <span>${createdDate}</span>
                    </div>
                    <div class="reply-message">${escapeHtml(ticket.message).replace(/\n/g, '<br>')}</div>
                </div>
            </div>
            <div style="border-top: 1px solid var(--gray-200); padding-top: 1rem;">
                <h6 style="margin-bottom: 1rem;">Conversation History</h6>
                ${repliesHtml}
            </div>
        `);
        
        $('#addReplyBtn').off('click').on('click', function() {
            if (ticket.status === 'closed' || ticket.status === 'resolved') {
                showAlert('error', 'This ticket is closed. Please create a new ticket.');
                return;
            }
            $('#replyTicketId').val(ticket.id);
            $('#replyMessage').val('');
            $('#replyModal').modal('show');
        });
        
        $('#closeTicketBtn').off('click').on('click', function() {
            if (ticket.status === 'closed' || ticket.status === 'resolved') {
                showAlert('error', 'Ticket is already closed');
                return;
            }
            if (confirm('Are you sure you want to close this ticket?')) {
                closeTicket(ticket.id);
            }
        });
        
        $('#closeTicketBtn').toggle(canClose);
    }
    
    function closeTicket(ticketId) {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'close_ticket',
                ticket_id: ticketId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#ticketModal').modal('hide');
                    loadTickets();
                    loadStats();
                    showAlert('success', response.message);
                } else {
                    showAlert('error', response.message);
                }
            },
            error: function() {
                showAlert('error', 'Error closing ticket');
            }
        });
    }
    
    function getStatusClass(status) {
        const classes = {
            'open': 'status-open',
            'in_progress': 'status-in_progress',
            'waiting': 'status-waiting',
            'resolved': 'status-resolved',
            'closed': 'status-closed'
        };
        return classes[status] || 'status-open';
    }
    
    function getStatusText(status) {
        const texts = {
            'open': 'Open',
            'in_progress': 'In Progress',
            'waiting': 'Waiting',
            'resolved': 'Resolved',
            'closed': 'Closed'
        };
        return texts[status] || status;
    }
    
    function getPriorityClass(priority) {
        const classes = {
            'low': 'priority-low',
            'medium': 'priority-medium',
            'high': 'priority-high',
            'urgent': 'priority-urgent'
        };
        return classes[priority] || 'priority-medium';
    }
    
    // Load stats
    function loadStats() {
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: { ajax_action: 'get_stats' },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.stats) {
                    const stats = response.stats;
                    $('#stat-total').text(stats.total_tickets || 0);
                    $('#stat-open').text(stats.open_tickets || 0);
                    $('#stat-progress').text(stats.in_progress || 0);
                    $('#stat-waiting').text(stats.waiting || 0);
                    $('#stat-resolved').text(stats.resolved || 0);
                    $('#stat-high').text(stats.high_priority || 0);
                }
            }
        });
    }
    
    // Create ticket
    $('#ticketForm').submit(function(e) {
        e.preventDefault();
        
        const subject = $('#ticketSubject').val().trim();
        const message = $('#ticketMessage').val().trim();
        
        if (!subject) {
            showAlert('error', 'Please enter a subject');
            return;
        }
        if (!message) {
            showAlert('error', 'Please enter your message');
            return;
        }
        
        const submitBtn = $('#submitTicketBtn');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Submitting...').prop('disabled', true);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'create_ticket',
                subject: subject,
                category: $('#ticketCategory').val(),
                priority: $('#ticketPriority').val(),
                message: message
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#ticketForm')[0].reset();
                    loadTickets();
                    loadStats();
                    showAlert('success', response.message);
                    
                    // Show the ticket details modal
                    if (response.ticket_id) {
                        viewTicket(response.ticket_id, response.ticket_number);
                    }
                } else {
                    showAlert('error', response.message);
                }
                submitBtn.html(originalText).prop('disabled', false);
            },
            error: function() {
                showAlert('error', 'Error creating ticket');
                submitBtn.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // Add reply
    $('#replyForm').submit(function(e) {
        e.preventDefault();
        
        const message = $('#replyMessage').val().trim();
        if (!message) {
            showAlert('error', 'Please enter your reply');
            return;
        }
        
        const submitBtn = $('#replyForm button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Sending...').prop('disabled', true);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'add_reply',
                ticket_id: $('#replyTicketId').val(),
                message: message
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#replyModal').modal('hide');
                    if (currentTicketId) {
                        viewTicket(currentTicketId, currentTicketNumber);
                    }
                    loadTickets();
                    showAlert('success', response.message);
                } else {
                    showAlert('error', response.message);
                }
                submitBtn.html(originalText).prop('disabled', false);
            },
            error: function() {
                showAlert('error', 'Error sending reply');
                submitBtn.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // WhatsApp Support
    $('#whatsappForm').submit(function(e) {
        e.preventDefault();
        
        const subject = $('#whatsappSubject').val().trim();
        const message = $('#whatsappMessage').val().trim();
        
        if (!message) {
            showAlert('error', 'Please enter your message');
            return;
        }
        
        // Prepare WhatsApp message
        let whatsappMsg = `*Customer Support Inquiry*\n\n`;
        whatsappMsg += `*Customer Name:* ${customerName}\n`;
        whatsappMsg += `*Customer ID:* <?= $customer_id ?>\n`;
        whatsappMsg += `*Phone:* ${customerPhone || 'Not provided'}\n`;
        if (subject) {
            whatsappMsg += `*Subject:* ${subject}\n`;
        }
        whatsappMsg += `\n*Message:*\n${message}\n\n`;
        whatsappMsg += `---\nSent from Cargo Management System Customer Portal`;
        
        const encodedMsg = encodeURIComponent(whatsappMsg);
        const formattedNumber = formatWhatsAppNumber(whatsappNumber);
        const whatsappUrl = `https://api.whatsapp.com/send?phone=${formattedNumber}&text=${encodedMsg}`;
        
        // Log the inquiry (optional)
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'send_whatsapp_inquiry',
                subject: subject,
                message: message
            },
            async: false
        });
        
        window.open(whatsappUrl, '_blank');
        
        // Clear form
        $('#whatsappSubject').val('');
        $('#whatsappMessage').val('');
        showAlert('success', 'Opening WhatsApp...');
    });
    
    // Quick WhatsApp button
    $('#whatsappSupportBtn').click(function(e) {
        e.preventDefault();
        
        let quickMsg = `Hello Cargo Management System Support Team,\n\nI need assistance with my account. My name is ${customerName} (Customer ID: <?= $customer_id ?>).\n\nPlease help me with: `;
        const encodedMsg = encodeURIComponent(quickMsg);
        const formattedNumber = formatWhatsAppNumber(whatsappNumber);
        const whatsappUrl = `https://api.whatsapp.com/send?phone=${formattedNumber}&text=${encodedMsg}`;
        window.open(whatsappUrl, '_blank');
    });
    
    // FAQ Modal
    function loadFaqs(category = 'all') {
        $('#faqList').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading FAQs...</div>');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'get_faqs',
                category: category
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.faqs) {
                    renderFaqs(response.faqs);
                } else {
                    $('#faqList').html('<div class="empty-state"><i class="fas fa-question-circle"></i><p>No FAQs found</p></div>');
                }
            },
            error: function() {
                $('#faqList').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading FAQs</p></div>');
            }
        });
    }
    
    function renderFaqs(faqs) {
        if (!faqs || faqs.length === 0) {
            $('#faqList').html('<div class="empty-state"><i class="fas fa-question-circle"></i><p>No FAQs in this category</p></div>');
            return;
        }
        
        let html = '';
        faqs.forEach(function(faq, index) {
            html += `
                <div class="faq-item">
                    <div class="faq-question" data-idx="${index}">
                        <span>${escapeHtml(faq.question)}</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer" id="faqAnswer${index}">
                        ${escapeHtml(faq.answer).replace(/\n/g, '<br>')}
                    </div>
                </div>
            `;
        });
        
        $('#faqList').html(html);
        
        $('.faq-question').off('click').on('click', function() {
            const idx = $(this).data('idx');
            $(`#faqAnswer${idx}`).toggleClass('active');
            $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
        });
    }
    
    $('#faqCategories .category-btn').click(function() {
        $('#faqCategories .category-btn').removeClass('active');
        $(this).addClass('active');
        const category = $(this).data('category');
        loadFaqs(category);
    });
    
    $('#createFromFaqBtn').click(function() {
        $('#faqModal').modal('hide');
        $('#ticketSubject').focus();
        $('html, body').animate({ scrollTop: 0 }, 300);
    });
    
    // Add FAQ button to header
    $('.card-header').each(function() {
        if ($(this).find('h3').text().includes('Create New Ticket')) {
            $(this).append(`
                <button class="btn-secondary" id="showFaqBtn" style="padding: 0.375rem 1rem;">
                    <i class="fas fa-question-circle"></i> FAQs
                </button>
            `);
        }
    });
    
    $(document).on('click', '#showFaqBtn', function() {
        loadFaqs('all');
        $('#faqModal').modal('show');
    });
    
    // Ticket status filter change
    $('#ticketStatusFilter').change(function() {
        currentPage = 1;
        loadTickets();
    });
    
    function showAlert(type, message) {
        const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
        const alertClass = type === 'success' ? 'alert-success' : (type === 'error' ? 'alert-error' : 'alert-info');
        $('#alert-placeholder').html(`
            <div class="alert ${alertClass} alert-dismissible fade show">
                <i class="fas ${icon}"></i> ${message}
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        `);
        setTimeout(function() {
            $('.alert').fadeOut(3000, function() { $(this).remove(); });
        }, 5000);
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    // Initialize
    loadTickets();
    loadStats();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
