<?php
require_once __DIR__ . '/includes/db.php';

// ToyyibPay hantar data guna status_id (1=success, 3=fail)
$status_id = $_GET['status_id'] ?? ''; 
$bill_code = $_GET['billcode'] ?? '';
$order_id  = $_GET['order_id'] ?? ''; // Ini ID dari database awak

// CARA PALING SELAMAT: Tanya database status terkini
$order = null;
if (!empty($order_id)) {
    $order = getOrderById($pdo, $order_id);
}

// Kita anggap berjaya kalau Toyyib cakap '1' ATAU database dah tulis 'paid'
$is_success = ($status_id == '1' || ($order && $order['payment_status'] == 'paid'));
?>

<!DOCTYPE html>
<html>
<head>
    <title>Order Status | Yadang's Time</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; text-align: center; padding: 50px; }
        .card { background: white; padding: 30px; border-radius: 10px; display: inline-block; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .success { color: #2ecc71; }
        .failed { color: #e74c3c; }
    </style>
</head>
<body>
    <div class="card">
    <?php if ($is_success): ?>
        <h1 class="success">✅ Payment Successful!</h1>
        <p>Thank you for your order. We are preparing your items now.</p>
        <p><strong>Order ID:</strong> <?php echo htmlspecialchars($order['order_number'] ?? $order_id); ?></p>
    <?php else: ?>
        <h1 class="failed">❌ Payment Failed</h1>
        <?php endif; ?>
        
        <br>
        <a href="home.php" style="text-decoration: none; color: #3498db;">Return to Home</a>
    </div>
</body>
</html>
