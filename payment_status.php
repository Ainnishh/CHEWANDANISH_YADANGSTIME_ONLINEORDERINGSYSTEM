<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/toyyibpay_config.php';

// Get parameters from ToyyibPay
$status_id = $_GET['status_id'] ?? '';
$billcode = $_GET['billcode'] ?? '';
$order_id = $_GET['order_id'] ?? '';
$transaction_id = $_GET['transaction_id'] ?? '';

// Log the callback
error_log("Payment Callback Received - Status: $status_id, Billcode: $billcode, Order: $order_id");

if (empty($order_id) && !empty($billcode)) {
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE toyyibpay_billcode = ?");
    $stmt->execute([$billcode]);
    $order = $stmt->fetch();
    if ($order) {
        $order_id = $order['id'];
    }
}

if (empty($order_id)) {
    die('Invalid request - No order ID');
}

// Order details
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found');
}

// Determine payment status
$payment_success = ($status_id == '1');
$payment_status = $payment_success ? 'paid' : 'failed';

if ($payment_success && !empty($billcode)) {
    // Verify bill status via API
    $postdata = [
        'userSecretKey' => TOYYIBPAY_SECRET_KEY,
        'billCode' => $billcode
    ];
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_URL, TOYYIBPAY_API_URL . 'index.php/api/getBillTransactions');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postdata);
    
    $result = curl_exec($curl);
    curl_close($curl);
    
    $transactions = json_decode($result, true);
    
    // Log verification
    error_log("Payment Verification for Order #$order_id: " . $result);
    
    // Update with transaction details
    if (!empty($transactions) && isset($transactions[0])) {
        $txn = $transactions[0];
        $payment_status = ($txn['billpaymentStatus'] == '1') ? 'paid' : 'failed';
        
        $stmt = $pdo->prepare("
            UPDATE orders SET 
                payment_status = ?,
                payment_reference = ?,
                toyyibpay_transaction_id = ?,
                toyyibpay_payment_method = ?,
                payment_date = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $payment_status,
            $txn['billpaymentInvoiceNo'] ?? $transaction_id,
            $txn['billpaymentTransactionID'] ?? $transaction_id,
            $txn['billpaymentChannel'] ?? 'online',
            $order_id
        ]);
        
        // Log payment
        $stmt = $pdo->prepare("INSERT INTO payment_logs (order_id, billcode, transaction_data, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$order_id, $billcode, json_encode($txn), $payment_status]);
    }
} else {
    $stmt = $pdo->prepare("UPDATE orders SET payment_status = ?, payment_reference = ? WHERE id = ?");
    $stmt->execute([$payment_status, $transaction_id, $order_id]);
}

// Clear any pending 
unset($_SESSION['pending_order']);

// Redirect based on payment status
if ($payment_success) {
    // Payment successful
    $_SESSION['payment_success'] = true;
    $_SESSION['order_number'] = $order['order_number'];
    header('Location: order_success.php?order=' . $order['order_number'] . '&payment=success');
} else {
    // Payment failed
    $_SESSION['payment_error'] = 'Payment failed. Please try again.';
    header('Location: cart.php?error=payment_failed');
}
exit();
?>
