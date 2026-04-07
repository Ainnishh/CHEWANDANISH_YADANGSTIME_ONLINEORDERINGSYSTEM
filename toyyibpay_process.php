<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/toyyibpay_config.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Get order ID
$order_id = $_GET['order_id'] ?? 0;

// Get order details
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$order_id, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found');
}

// Get order items for description
$stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll();

// Prepare bill data for ToyyibPay
$bill_name = "Yadang's Time Order #" . $order['order_number'];
$bill_description = generateBillDescription($items);
$bill_amount = $order['total_amount'] * 100; // Convert to cents
$bill_return_url = TOYYIBPAY_RETURN_URL . '?order_id=' . $order_id;
$bill_callback_url = TOYYIBPAY_CALLBACK_URL;
$bill_external_id = $order['order_number'];
$bill_to = $order['customer_name'];
$bill_email = $order['customer_email'] ?? 'customer@email.com';
$bill_phone = $order['customer_phone'];

// API parameters
$postdata = [
    'userSecretKey' => TOYYIBPAY_SECRET_KEY,
    'categoryCode' => TOYYIBPAY_CATEGORY_CODE,
    'billName' => $bill_name,
    'billDescription' => $bill_description,
    'billPriceSetting' => 1,
    'billPayorInfo' => 1,
    'billAmount' => $bill_amount,
    'billReturnUrl' => $bill_return_url,
    'billCallbackUrl' => $bill_callback_url,
    'billExternalReferenceNo' => $bill_external_id,
    'billTo' => $bill_to,
    'billEmail' => $bill_email,
    'billPhone' => $bill_phone,
    'billSplitPayment' => 0,
    'billSplitPaymentArgs' => '',
    'billPaymentChannel' => 0, // 0 = all channels
    'billContentEmail' => "Thank you for ordering at Yadang's Time! Please complete your payment.",
    'billChargeToCustomer' => 1
];

// Log request
error_log("ToyyibPay Request: " . json_encode($postdata));

// Create bill via API
$curl = curl_init();
curl_setopt($curl, CURLOPT_POST, 1);
curl_setopt($curl, CURLOPT_URL, TOYYIBPAY_API_URL . 'index.php/api/createBill');
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, $postdata);

$result = curl_exec($curl);
$info = curl_getinfo($curl);
$error = curl_error($curl);

curl_close($curl);

// Log response
error_log("ToyyibPay Response: " . $result);
error_log("Curl Info: " . json_encode($info));
if ($error) error_log("Curl Error: " . $error);

// Parse response
$response = json_decode($result, true);

if (isset($response[0]['BillCode'])) {
    $billcode = $response[0]['BillCode'];
    
    // Update order with billcode
    $stmt = $pdo->prepare("UPDATE orders SET toyyibpay_billcode = ? WHERE id = ?");
    $stmt->execute([$billcode, $order_id]);
    
    // Log payment
    $stmt = $pdo->prepare("INSERT INTO payment_logs (order_id, billcode, response_data) VALUES (?, ?, ?)");
    $stmt->execute([$order_id, $billcode, json_encode($response)]);
    
    // Redirect to ToyyibPay payment page
    $payment_url = TOYYIBPAY_API_URL . $billcode;
    header('Location: ' . $payment_url);
    exit();
    
} else {
    // Error creating bill
    error_log("ToyyibPay Bill Creation Failed for Order #" . $order_id);
    $_SESSION['payment_error'] = 'Unable to create payment bill. Please try again.';
    header('Location: cart.php?error=payment_failed');
    exit();
}
?>
