<?php
session_start();
require_once 'includes/db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;
$order_id = $_GET['id'] ?? 0;

if (empty($order_id)) {
    header('Location: order_history.php');
    exit();
}

// Get order details
$stmt = $pdo->prepare("
    SELECT * FROM orders 
    WHERE id = ? AND user_id = ?
");
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: order_history.php');
    exit();
}

// Get order items
$stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt->execute([$order_id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details | Yadang's Time</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --dark-bg: #1a2c22;
            --container-bg: #2d4638;
            --accent-color: #FAA18F;
            --text-light: #E8DFD0;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
        }
        
        body {
            background: linear-gradient(135deg, #1a2c22, #2d4638);
            color: #E8DFD0;
            font-family: 'Segoe UI', sans-serif;
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 800px;
            margin: 2rem auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .back-link {
            color: #FAA18F;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .back-link:hover {
            transform: translateX(-5px);
        }
        
        .order-number {
            font-size: 1.5rem;
            color: #FAA18F;
            font-weight: bold;
        }
        
        .card {
            background: #2d4638;
            border-radius: 15px;
            padding: 2rem;
            border: 1px solid rgba(250,161,143,0.2);
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .card h2 {
            color: #FAA18F;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid rgba(250,161,143,0.3);
        }
        
        .status-group {
            display: flex;
            gap: 1rem;
            margin: 1rem 0;
            flex-wrap: wrap;
        }
        
        .status-badge {
            padding: 0.5rem 1.5rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: bold;
        }
        
        .status-pending {
            background: rgba(241, 196, 15, 0.2);
            color: #f39c12;
            border: 1px solid #f39c12;
        }
        
        .status-processing {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            border: 1px solid #3498db;
        }
        
        .status-completed {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
            border: 1px solid #2ecc71;
        }
        
        .status-cancelled {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }
        
        .payment-paid {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
            border: 1px solid #2ecc71;
        }
        
        .payment-pending {
            background: rgba(241, 196, 15, 0.2);
            color: #f39c12;
            border: 1px solid #f39c12;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(250,161,143,0.1);
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: rgba(232,223,208,0.7);
        }
        
        .detail-value {
            font-weight: bold;
            color: #FAA18F;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        
        .items-table th {
            text-align: left;
            padding: 1rem 0;
            border-bottom: 2px solid #FAA18F;
            color: #FAA18F;
        }
        
        .items-table td {
            padding: 1rem 0;
            border-bottom: 1px solid rgba(250,161,143,0.1);
        }
        
        .customization-note {
            color: rgba(232,223,208,0.5);
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }
        
        .total-section {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 2px solid #FAA18F;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
        }
        
        .grand-total {
            font-size: 1.3rem;
            font-weight: bold;
            color: #FAA18F;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid rgba(250,161,143,0.3);
        }
        
        .btn {
            background: #FAA18F;
            color: #0f1913;
            padding: 0.8rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            font-weight: bold;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250,161,143,0.3);
        }
        
        .btn-secondary {
            background: transparent;
            border: 2px solid #FAA18F;
            color: #FAA18F;
        }
        
        .text-center {
            text-align: center;
        }
        
        @media (max-width: 600px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .items-table {
                font-size: 0.9rem;
            }
            
            .items-table th, .items-table td {
                padding: 0.5rem 0;
            }
        }
    </style>
</head>
<body>
    
            <!-- GUNA ORDER_CODE BUKAN ORDER_NUMBER -->
            <span class="order-number">#<?php echo htmlspecialchars($order['order_number']); ?></span>
        </div>
        
        <!-- Order Status Card -->
        <div class="card">
            <h2>Order Status</h2>
            
            <div class="status-group">
                <span class="status-badge status-<?php echo $order['status']; ?>">
                    <i class="fas fa-clock"></i> Status: <?php echo ucfirst($order['status']); ?>
                </span>
                <span class="status-badge payment-<?php echo $order['payment_status']; ?>">
                    <i class="fas fa-credit-card"></i> Payment: <?php echo ucfirst($order['payment_status']); ?>
                </span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Order Date</span>
                <span class="detail-value">
                    <?php 
                    if (!empty($order['order_date'])) {
                        echo date('d M Y, h:i A', strtotime($order['order_date']));
                    } elseif (!empty($order['created_at'])) {
                        echo date('d M Y, h:i A', strtotime($order['created_at']));
                    }
                    ?>
                </span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Payment Method</span>
                <span class="detail-value"><?php echo ucfirst($order['payment_method']); ?></span>
            </div>
            
            <?php if (!empty($order['delivery_address'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Delivery Address</span>
                    <span class="detail-value"><?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($order['special_instructions'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Special Instructions</span>
                    <span class="detail-value"><?php echo nl2br(htmlspecialchars($order['special_instructions'])); ?></span>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Items Card -->
        <div class="card">
            <h2>Items Ordered</h2>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <?php if (!empty($item['customization'])): ?>
                                    <div class="customization-note">
                                        <?php 
                                        $custom = json_decode($item['customization'], true);
                                        if ($custom) {
                                            $notes = [];
                                            if (!empty($custom['sweetness'])) $notes[] = "Sugar: " . $custom['sweetness'];
                                            if (!empty($custom['milk'])) $notes[] = "Milk: " . $custom['milk'];
                                            if (!empty($custom['syrup'])) $notes[] = "Syrup: " . $custom['syrup'];
                                            echo implode(' • ', $notes);
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td>RM <?php echo number_format($item['price'], 2); ?></td>
                            <td>RM <?php echo number_format($item['subtotal'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="total-section">
                <div class="total-row">
                    <span>Subtotal</span>
                    <span>RM <?php echo number_format($order['subtotal'] ?? 0, 2); ?></span>
                </div>
                <div class="total-row">
                    <span>Delivery Fee</span>
                    <span>RM <?php echo number_format($order['delivery_fee'] ?? 0, 2); ?></span>
                </div>
                <div class="total-row">
                    <span>Tax (6%)</span>
                    <span>RM <?php echo number_format($order['tax_amount'] ?? 0, 2); ?></span>
                </div>
                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                <div class="total-row" style="color: #2ecc71;">
                    <span>Discount</span>
                    <span>-RM <?php echo number_format($order['discount_amount'], 2); ?></span>
                </div>
                <?php endif; ?>
                <div class="grand-total">
                    <span>Total</span>
                    <span>RM <?php echo number_format($order['total_amount'] ?? 0, 2); ?></span>
                </div>
            </div>
        </div>
        
        <div class="text-center">
            <a href="menu.php" class="btn">Order Again</a>
            <a href="order_history.php" class="btn btn-secondary" style="margin-left: 1rem;">Back to History</a>
        </div>
    </div>
</body>
</html>
