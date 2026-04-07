<?php
// ToyyibPay Configuration
define('TOYYIBPAY_SECRET_KEY', '6ii73qb0-5obi-iur6-73mx-zkdz3i50wyeu'); // Ganti dengan secret key anda
define('TOYYIBPAY_CATEGORY_CODE', 'ya5jd2ns'); // Ganti dengan category code anda
define('TOYYIBPAY_USER_EMAIL', 'nishhr27@gmail.com'); // Email toyyibpay account

// Environment (production/sandbox)
define('TOYYIBPAY_SANDBOX', true); // Set false untuk production

// API URLs
if (TOYYIBPAY_SANDBOX) {
    define('TOYYIBPAY_API_URL', 'https://dev.toyyibpay.com/e/143251053252805'); // Sandbox
    define('TOYYIBPAY_RETURN_URL', 'https://trashy-kiley-objectionable.ngrok-free.dev'); // Ganti dengan ngrok url
} else {
    define('TOYYIBPAY_API_URL', 'https://toyyibpay.com/'); // Production
    define('TOYYIBPAY_RETURN_URL', 'https://yourdomain.com/yadangs/payment_status.php');
}

define('TOYYIBPAY_CALLBACK_URL', TOYYIBPAY_RETURN_URL); // Same as return URL for now

// Bill expiration (in hours)
define('TOYYIBPAY_BILL_EXPIRY', 24); // 24 hours

// Payment methods
$payment_methods = [
    'cash' => '💵 Cash on Delivery',
    'online' => '💳 Online Payment (FPX/Card)'
];

// Bill descriptions
function generateBillDescription($items) {
    if (empty($items)) return 'Order at Yadang\'s Time';
    
    $descriptions = [];
    foreach ($items as $item) {
        $descriptions[] = $item['quantity'] . 'x ' . $item['name'];
    }
    return implode(', ', $descriptions);
}
?>
