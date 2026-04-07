<?php
session_start();
require_once 'includes/db.php';

// ERROR REPORTING
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Check if cart is empty
if (empty($_SESSION['cart'])) {
    $_SESSION['checkout_error'] = 'Your cart is empty';
    header('Location: cart.php');
    exit();
}

// Calculate totals from cart
$cart = $_SESSION['cart'];
$subtotal = 0;
$items_data = [];

foreach ($cart as $item_id => $item) {
    $base_price = floatval($item['price'] ?? 0);
    $quantity = intval($item['quantity'] ?? 1);
    
    // Calculate extra charges
    $extra = 0;
    if (isset($item['customizations']['milk']) && $item['customizations']['milk'] === 'oat') {
        $extra += 2.00;
    }
    if (isset($item['customizations']['syrup']) && $item['customizations']['syrup'] !== 'none') {
        $extra += 1.00;
    }
    
    $final_price = $base_price + $extra;
    $item_subtotal = $final_price * $quantity;
    $subtotal += $item_subtotal;
    
    $items_data[] = [
        'id' => $item_id,
        'name' => $item['name'],
        'quantity' => $quantity,
        'price' => $final_price,
        'base_price' => $base_price,
        'extra' => $extra,
        'customizations' => $item['customizations'] ?? [],
        'subtotal' => $item_subtotal
    ];
}

// Apply promo if any
$discount = isset($_SESSION['discount']) ? $subtotal * $_SESSION['discount'] : 0;
$delivery_fee = isset($_SESSION['free_shipping']) ? 0 : 3.00;
$tax = $subtotal * 0.06; // 6% SST
$total = $subtotal + $delivery_fee + $tax - $discount;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout'])) {
    
    $customer_name = $_POST['customer_name'] ?? '';
    $customer_phone = $_POST['customer_phone'] ?? '';
    $customer_email = $_POST['customer_email'] ?? '';
    $delivery_address = $_POST['delivery_address'] ?? '';
    $special_instructions = $_POST['special_instructions'] ?? '';
    $payment_method = $_POST['payment_method'] ?? 'cash';
    
    // Debug output
    error_log("Checkout Data - Name: $customer_name, Phone: $customer_phone, Payment: $payment_method");
    
    // Validation
    $errors = [];
    if (empty($customer_name)) $errors[] = 'Name is required';
    if (empty($customer_phone)) $errors[] = 'Phone is required';
    if (empty($delivery_address)) $errors[] = 'Delivery address is required';
    
    if (!empty($errors)) {
        $_SESSION['checkout_errors'] = $errors;
        header('Location: cart.php');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        error_log("Transaction Started");
        
        // Generate order number
        $order_number = generateOrderNumber($pdo);
        error_log("Order Number: $order_number");
        
        // Insert order
        $sql = "INSERT INTO orders (
            order_number, user_id, customer_name, customer_phone, customer_email,
            delivery_address, special_instructions, subtotal, delivery_fee,
            tax_amount, discount_amount, total_amount, payment_method, payment_status, status
        ) VALUES (
            :order_number, :user_id, :customer_name, :customer_phone, :customer_email,
            :delivery_address, :special_instructions, :subtotal, :delivery_fee,
            :tax_amount, :discount_amount, :total_amount, :payment_method, 'pending', 'pending'
        )";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':order_number' => $order_number,
            ':user_id' => $_SESSION['user_id'],
            ':customer_name' => $customer_name,
            ':customer_phone' => $customer_phone,
            ':customer_email' => $customer_email,
            ':delivery_address' => $delivery_address,
            ':special_instructions' => $special_instructions,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $delivery_fee,
            ':tax_amount' => $tax,
            ':discount_amount' => $discount,
            ':total_amount' => $total,
            ':payment_method' => $payment_method
        ]);
        
        error_log("Order Insert Result: " . ($result ? 'Success' : 'Failed'));
        
        $order_id = $pdo->lastInsertId();
        error_log("Order ID: $order_id");
        
        // Insert order items
        $item_sql = "INSERT INTO order_items (order_id, item_id, item_name, quantity, price, subtotal, customizations)
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
        $item_stmt = $pdo->prepare($item_sql);
        
        foreach ($items_data as $item) {
            $customizations_json = json_encode($item['customizations']);
            error_log("Inserting item: {$item['name']} x {$item['quantity']}");
            
            $item_stmt->execute([
                $order_id,
                $item['id'],
                $item['name'],
                $item['quantity'],
                $item['price'],
                $item['subtotal'],
                $customizations_json
            ]);
        }
        
        $pdo->commit();
        error_log("Transaction Committed Successfully");
        
        // Clear cart after successful order creation
        $_SESSION['cart'] = [];
        unset($_SESSION['discount']);
        unset($_SESSION['free_shipping']);
        
        // Set success message
        $_SESSION['order_success'] = true;
        $_SESSION['order_number'] = $order_number;
        
        // ===== REDIRECT BASED ON PAYMENT METHOD =====
        if ($payment_method === 'online') {
            // ONLINE PAYMENT - Redirect to ToyyibPay
            $baseUrl = "https://trashy-kiley-objectionable.ngrok-free.dev/yadangs-time"; 

            $bill_data = array(
                'userSecretKey' => '6ii73qb0-5obi-iur6-73mx-zkdz3i50wyeu',
                'categoryCode'  => 'ya5jd2ns',
                'billName'      => 'YTsOrder #' . $order_number,
                'billDescription' => 'Payment for order ' . $order_number,
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount'    => $total * 100,
                'billReturnUrl' => $baseUrl . '/return.php',
                'billCallbackUrl' => $baseUrl . '/callback.php',
                'billExternalReferenceNo' => $order_id, 
                'billTo'        => $customer_name,
                'billEmail'     => $customer_email,
                'billPhone'     => $customer_phone,
            );

            $ch = curl_init('https://dev.toyyibpay.com/index.php/api/createBill');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($bill_data));
            $result = curl_exec($ch);
            curl_close($ch);

            $response = json_decode($result, true);

            if (!empty($response[0]['BillCode'])) {
                $billCode = $response[0]['BillCode'];
                
                // Panggil function dari db.php - TANPA declare semula
                if (function_exists('updateOrderBillcode')) {
                    updateOrderBillcode($pdo, $order_id, $billCode);
                } else {
                    // Fallback jika function takde
                    $stmt = $pdo->prepare("UPDATE orders SET toyyibpay_billcode = ? WHERE id = ?");
                    $stmt->execute([$billCode, $order_id]);
                }
            
                // Redirect to ToyyibPay
                header("Location: https://dev.toyyibpay.com/" . $billCode);
                exit();
            } else {
                // Kalau gagal, redirect dengan error
                $_SESSION['payment_error'] = "Payment gateway error: " . ($response['msg'] ?? 'Unknown error');
                header('Location: cart.php?error=payment_failed');
                exit();
            }
        } else {
            // ===== CASH ON DELIVERY =====
            header('Location: order_success.php?order=' . urlencode($order_number) . '&method=cash');
            exit();
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("CHECKOUT ERROR: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        $_SESSION['checkout_error'] = 'Error processing order: ' . $e->getMessage();
        header('Location: cart.php?error=checkout_failed');
        exit();
    }
}

// If not POST, redirect to cart
header('Location: cart.php');
exit();
?>
