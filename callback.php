<?php
require_once 'includes/db.php';

// toyyibPay sends payment data via POST
$status = $_POST['status'] ?? ''; // 1=Success, 2=Pending, 3=Failed
$order_id = $_POST['order_id'] ?? ''; // This is the ID from your MySQL 'orders' table
$transaction_id = $_POST['transaction_id'] ?? '';
$billcode = $_POST['billcode'] ?? '';

// Check if it's a success
if ($status == '1') {
    // 1. Update status to 'paid' using your existing PDO function
    // We pass $transaction_id to both reference columns as per your function
    updatePaymentStatus($pdo, $order_id, 'paid', $transaction_id);
    
    // 2. Log it into your payment_logs table
    logPayment($pdo, $order_id, $billcode, $_POST, 'Success');
    
    // 3. Crucial: You MUST echo "OK" so toyyibPay knows you received it
    echo "OK";
} else {
    // Payment failed or was cancelled
    updatePaymentStatus($pdo, $order_id, 'failed');
    logPayment($pdo, $order_id, $billcode, $_POST, 'Failed');
}
?>
