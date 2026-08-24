<?php
require_once 'c:\xampp2\htdocs\curdub_smart_cargo (2)\curdub_smart_cargo\config\db_connect.php';

echo "Isku dhabaynta xisaabaadka macaamiisha...\n";

try {
    $pdo->beginTransaction();

    $customers = $pdo->query("SELECT id, customer_name FROM customers")->fetchAll();

    foreach ($customers as $customer) {
        $id = $customer['id'];
        
        $stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM invoices WHERE customer_id = ?");
        $stmt->execute([$id]);
        $total_invoiced = (float)$stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM receipts WHERE customer_id = ?");
        $stmt->execute([$id]);
        $total_paid = (float)$stmt->fetch()['total'];
        
        $new_debt = $total_invoiced - $total_paid;
        
        $update = $pdo->prepare("UPDATE customers SET debt_amount = ?, updated_at = NOW() WHERE id = ?");
        $update->execute([$new_debt, $id]);
        
        echo "Macaamil: {$customer['customer_name']} -> Balance: $$new_debt\n";
    }

    $pdo->commit();
    echo "\nGuul! Xisaabtu waa sax hadda.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
