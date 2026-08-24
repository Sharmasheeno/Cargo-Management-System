<?php
require_once __DIR__ . '/config/db_connect.php';

echo "Recalculating customer debt balances (Invoices - Receipts)...\n";

try {
    // 1. Reset all debt_amount to 0
    $pdo->exec("UPDATE customers SET debt_amount = 0");
    
    // 2. Get total invoiced per customer
    $stmtInv = $pdo->query("SELECT customer_id, SUM(total_amount) as total_inv FROM invoices GROUP BY customer_id");
    $invoiced = $stmtInv->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // 3. Get total paid (receipts) per customer
    $stmtRec = $pdo->query("SELECT customer_id, SUM(amount) as total_paid FROM receipts GROUP BY customer_id");
    $paid = $stmtRec->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // 4. Get all customer IDs
    $stmtCust = $pdo->query("SELECT id FROM customers");
    $customers = $stmtCust->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($customers as $cid) {
        $invTotal = $invoiced[$cid] ?? 0;
        $paidTotal = $paid[$cid] ?? 0;
        $outstanding = $invTotal - $paidTotal;
        
        if ($outstanding != 0) {
            $update = $pdo->prepare("UPDATE customers SET debt_amount = ? WHERE id = ?");
            $update->execute([$outstanding, $cid]);
            echo "Customer ID $cid: Invoiced=$invTotal, Paid=$paidTotal, Outstanding=$outstanding\n";
        }
    }
    
    echo "Done! All customer balances synchronized.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
