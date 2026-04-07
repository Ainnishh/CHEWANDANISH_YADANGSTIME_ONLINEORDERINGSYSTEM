<?php
include('db.php');
session_start();

// 1. toyyibPay Credentials
$userSecretKey = '6ii73qb0-5obi-iur6-73mx-zkdz3i50wyeu'; 
$categoryCode  = 'ya5jd2ns';

// 2. Prepare Order Data (Using your calculateCartTotals function)
$cart = $_SESSION['cart'] ?? [];
$totals = calculateCartTotals($cart);

$order_data = [
    'order_number' => generateOrderNumber($pdo),
    'user_id' => $_SESSION['user_id'],
    'customer_name' => $_SESSION['user_name'],
    'customer_phone' => $_SESSION['user_phone'],
    'customer_email' => $_SESSION['user_email'],
    'delivery_address' => $_POST['address'],
    'special_instructions' => $_POST['notes'] ?? '',
    'subtotal' => $totals['subtotal'],
    'delivery_fee' => $totals['delivery_fee'],
    'tax_amount' => $totals['tax'],
    'discount_amount' => $totals['discount'],
    'total_amount' => $totals['total'],
    'payment_method' => 'toyyibpay'
];

// 3. Insert Order into your MySQL
$order_id = createOrder($pdo, $order_data);

if ($order_id) {
    // 4. Prepare toyyibPay API Call
    $url = 'https://dev.toyyibpay.com/index.php/api/createBill';
    $bill_data = array(
        'userSecretKey' => $userSecretKey,
        'categoryCode' => $categoryCode,
        'billName' => 'YTsOrder',
        'billDescription' => 'Payment for Order ' . $order_data['order_number'],
        'billPriceSetting' => 1,
        'billPayorInfo' => 1,
        'billAmount' => $totals['total'] * 100, // Convert to cents
        'billReturnUrl' => 'http://localhost/yadangs-time/return.php',
        'billCallbackUrl' => 'https://trashy-kiley-objectionable.ngrok-free.dev/yadangs-time/callback.php',
        'billExternalReferenceNo' => $order_id, // We pass the DB ID to the callback
        'billTo' => $order_data['customer_name'],
        'billEmail' => $order_data['customer_email'],
        'billPhone' => $order_data['customer_phone']
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bill_data);
    $result = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($result, true);

    if (!empty($response[0]['BillCode'])) {
        $billCode = $response[0]['BillCode'];
        
        // Update your DB with the billcode using your helper function
        updateOrderBillcode($pdo, $order_id, $billCode);
        
        // Redirect to toyyibPay
        header("Location: https://dev.toyyibpay.com/" . $billCode);
    }
}
?>
