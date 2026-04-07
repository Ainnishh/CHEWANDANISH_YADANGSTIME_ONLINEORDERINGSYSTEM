<?php
session_start();
require_once 'includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$order_number = $_GET['order'] ?? $_SESSION['order_number'] ?? '';
$payment_method = $_GET['payment'] ?? 'cash';

// Get order details
$order = null;
$items = [];

if (!empty($order_number)) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? AND user_id = ?");
    $stmt->execute([$order_number, $_SESSION['user_id']]);
    $order = $stmt->fetch();
    
    if ($order) {
        $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll();
    }
}

if (!$order) {
    header('Location: home.php');
    exit();
}

// Clear session flags
unset($_SESSION['order_success']);
unset($_SESSION['order_number']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Order Success | Yadang's Time</title>
    <style>
        body { font-family: Arial; background: #1a2c22; color: #E8DFD0; }
        .success-box { max-width: 600px; margin: 50px auto; background: #2d4638; padding: 30px; border-radius: 10px; text-align: center; }
        h1 { color: #FAA18F; }
        .order-number { font-size: 24px; color: #FAA18F; margin: 20px 0; }
        .btn { background: #FAA18F; color: #1a2c22; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px; }
    </style>
</head>
<body>
    <div class="success-box">
        <h1>✅ Order Placed Successfully!</h1>
        <p>Thank you for your order!</p>
        
        <div class="order-number">
            Order #: <?php echo htmlspecialchars($order['order_number']); ?>
        </div>
        
        <p>Total: RM <?php echo number_format($order['total_amount'], 2); ?></p>
        
        <?php if ($payment_method == 'cash'): ?>
            <p style="color: #2ecc71;">💵 Please pay upon delivery</p>
        <?php endif; ?>
        
        <a href="home.php" class="btn">Back to Home</a>
        <a href="menu.php" class="btn">Order More</a>
    </div>
</body>
</html>
