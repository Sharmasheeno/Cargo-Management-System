<?php
// reports/receivable_report.php
// Accounts Receivable Report - Aging analysis and debt tracking

// Get receivable data
$total_receivable = 0;
$overdue_amount = 0;
$customer_debt = 0;
$aging_buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
$receivable_list = [];

try {
    // Total receivable
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0) as total
        FROM invoices 
        WHERE tenant_id = ? AND status != 'paid'
    ");
    $stmt->execute([$session_tenant_id]);
    $total_receivable = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Overdue amount
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0) as total
        FROM invoices 
        WHERE tenant_id = ? AND status = 'overdue'
    ");
    $stmt->execute([$session_tenant_id]);
    $overdue_amount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Customer debt total
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(debt_amount), 0) as total FROM customers WHERE tenant_id = ?");
    $stmt->execute([$session_tenant_id]);
    $customer_debt = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Aging analysis
    $stmt = $pdo->prepare("
        SELECT 
            DATEDIFF(NOW(), due_date) as days_overdue,
            (total_amount - paid_amount) as due_amount
        FROM invoices 
        WHERE tenant_id = ? AND status != 'paid' AND (total_amount - paid_amount) > 0
    ");
    $stmt->execute([$session_tenant_id]);
    $aging_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($aging_invoices as $inv) {
        $days = (int)$inv['days_overdue'];
        $amount = (float)$inv['due_amount'];
        if ($days <= 30) $aging_buckets['0-30'] += $amount;
        elseif ($days <= 60) $aging_buckets['31-60'] += $amount;
        elseif ($days <= 90) $aging_buckets['61-90'] += $amount;
        else $aging_buckets['90+'] += $amount;
    }
    
    // Build WHERE clause
    $where = "i.tenant_id = ? AND i.status != 'paid'";
    $params = [$session_tenant_id];
    
    if ($customer_id) {
        $where .= " AND i.customer_id = ?";
        $params[] = $customer_id;
    }
    if ($status) {
        $where .= " AND i.status = ?";
        $params[] = $status;
    }
    
    // Receivable list
    $stmt = $pdo->prepare("
        SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
               c.customer_name, c.phone, c.email,
               i.total_amount, i.paid_amount,
               (i.total_amount - i.paid_amount) as balance,
               i.status, DATEDIFF(NOW(), i.due_date) as days_overdue
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE $where AND (i.total_amount - i.paid_amount) > 0
        ORDER BY i.due_date ASC
        LIMIT 500
    ");
    $stmt->execute($params);
    $receivable_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {}
?>

<!-- Summary Cards -->
<div class="row">
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-receipt"></i> Total Receivable</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatMoney($total_receivable) ?></div>
            <div class="subtext">Outstanding invoices</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-exclamation-triangle"></i> Overdue Amount</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatMoney($overdue_amount) ?></div>
            <div class="subtext">Past due payments</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fas fa-users"></i> Customer Debt</h4>
            <div class="amount" style="color: var(--curdun-danger);"><?= formatMoney($customer_debt) ?></div>
            <div class="subtext">From customer records</div>
        </div>
    </div>
</div>

<!-- Aging Analysis -->
<div class="chart-container mt-3">
    <div class="chart-title"><i class="fas fa-hourglass-half"></i> Accounts Receivable Aging</div>
    <table class="aging-table">
        <?php
        $total_aging = array_sum($aging_buckets);
        $colors = ['#28a745', '#ffc107', '#fd7e14', '#dc3545'];
        $i = 0;
        foreach ($aging_buckets as $bucket => $amount):
            $percentage = $total_aging > 0 ? ($amount / $total_aging) * 100 : 0;
        ?>
        <tr>
            <td width="100"><strong><?= $bucket ?> Days</strong></td>
            <td width="150" class="text-end"><?= formatMoney($amount) ?></td>
            <td width="80"><?= number_format($percentage, 1) ?>%</td>
            <td>
                <div class="aging-progress">
                    <div class="aging-bar" style="width: <?= $percentage ?>%; background: <?= $colors[$i] ?>;"></div>
                </div>
            </td>
        </tr>
        <?php $i++; endforeach; ?>
        <tr style="border-top: 2px solid #ddd;">
            <td><strong>Total</strong></td>
            <td class="text-end"><strong><?= formatMoney($total_aging) ?></strong></td>
            <td><strong>100%</strong></td>
            <td></td>
        </tr>
    </table>
</div>

<!-- Receivable List -->
<div class="chart-container mt-3">
    <div class="chart-title">
        <i class="fas fa-list"></i> Outstanding Invoices
        <div class="float-end">
            <button class="btn-action btn-action-warning me-2" onclick="sendBulkReminder()"><i class="fab fa-whatsapp"></i> WhatsApp Reminder</button>
            <button class="btn-action btn-action-warning" onclick="sendBulkSMS()"><i class="fas fa-envelope"></i> SMS Reminder</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table" id="receivableTable">
            <thead>
                <tr>
                    <th>Invoice #</th><th>Customer</th><th>Phone</th><th>Due Date</th>
                    <th class="text-end">Total</th><th class="text-end">Paid</th>
                    <th class="text-end">Balance</th><th>Days Overdue</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receivable_list as $inv): ?>
                <tr>
                    <td><?= htmlspecialchars($inv['invoice_number'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($inv['customer_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($inv['phone'] ?? '-') ?></td>
                    <td class="<?= $inv['days_overdue'] > 0 ? 'text-danger' : '' ?>">
                        <?= date('d/m/Y', strtotime($inv['due_date'])) ?>
                    </td>
                    <td class="text-end"><?= formatMoney($inv['total_amount']) ?></td>
                    <td class="text-end"><?= formatMoney($inv['paid_amount']) ?></td>
                    <td class="text-end text-danger"><?= formatMoney($inv['balance']) ?></td>
                    <td class="text-danger"><?= max(0, $inv['days_overdue']) ?> days</td>
                    <td>
                        <button class="btn-action" onclick="sendReminder(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['customer_name']) ?>', '<?= formatMoney($inv['balance']) ?>', '<?= date('d/m/Y', strtotime($inv['due_date'])) ?>')"><i class="fab fa-whatsapp"></i></button>
                        <button class="btn-action" onclick="viewInvoice(<?= $inv['id'] ?>)"><i class="fas fa-eye"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#receivableTable').DataTable({ pageLength: 25, order: [[3, 'asc']] });
});

function sendReminder(invoiceId, customerName, amount, dueDate) {
    Swal.fire({
        title: 'Send Reminder',
        text: `Send payment reminder to ${customerName} for ${amount}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Send WhatsApp',
        cancelButtonText: 'Send SMS',
        showDenyButton: true,
        denyButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // WhatsApp reminder
            $.post('ajax/send_reminder.php', { invoice_id: invoiceId, type: 'whatsapp' }, function(response) {
                Swal.fire('Sent!', 'WhatsApp reminder sent successfully', 'success');
            });
        } else if (result.isDismissed && result.dismiss === 'cancel') {
            // SMS reminder
            $.post('ajax/send_reminder.php', { invoice_id: invoiceId, type: 'sms' }, function(response) {
                Swal.fire('Sent!', 'SMS reminder sent successfully', 'success');
            });
        }
    });
}

function sendBulkReminder() {
    Swal.fire({
        title: 'Bulk WhatsApp Reminder',
        text: 'Send WhatsApp reminders to all customers with overdue payments?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, send all'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax/bulk_reminder.php', { type: 'whatsapp' }, function(response) {
                Swal.fire('Done!', 'Bulk reminders sent successfully', 'success');
            });
        }
    });
}

function sendBulkSMS() {
    Swal.fire({
        title: 'Bulk SMS Reminder',
        text: 'Send SMS reminders to all customers with overdue payments?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, send all'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('ajax/bulk_reminder.php', { type: 'sms' }, function(response) {
                Swal.fire('Done!', 'Bulk SMS sent successfully', 'success');
            });
        }
    });
}

function viewInvoice(id) {
    window.open('view_invoice.php?id=' + id, '_blank');
}
</script>