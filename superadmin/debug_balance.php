<?php
require_once 'c:\xampp2\htdocs\curdub_smart_cargo (2)\curdub_smart_cargo\config\db_connect.php';
$id = 4;
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM invoices WHERE customer_id = ?");
$stmt->execute([$id]);
$total_invoiced = (float)$stmt->fetch()['total'];
$stmt = $pdo->prepare("SELECT SUM(amount) as total FROM receipts WHERE customer_id = ?");
$stmt->execute([$id]);
$total_paid = (float)$stmt->fetch()['total'];
$new_debt = $total_invoiced - $total_paid;
echo "Invoices: $total_invoiced, Receipts: $total_paid, Balance: $new_debt\n";
