<?php
session_start();
require_once 'includes/db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;

// Get orders from database
try {
    // Get orders for this user - GUNA ORDER_CODE BUKAN ORDER_NUMBER
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
        FROM orders o 
        WHERE o.user_id = ? 
        ORDER BY o.id DESC
    ");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll();

    // Calculate stats
    $total_orders = count($orders);
    $total_spent = 0;
    $pending_orders = 0;
    
    // Get items for each order and calculate stats
    foreach ($orders as &$order) {
        // Add to total spent
        $total_spent += floatval($order['total_amount'] ?? 0);
        
        // Count pending orders
        if (($order['status'] ?? '') == 'pending') {
            $pending_orders++;
        }
        
        // Get order items
        $stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt_items->execute([$order['id']]);
        $order['items'] = $stmt_items->fetchAll();
    }
    
} catch (PDOException $e) {
    error_log("Order History Error: " . $e->getMessage());
    $orders = [];
    $total_orders = 0;
    $total_spent = 0;
    $pending_orders = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History | Yadang's Time</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --dark-bg: #1a2c22;
            --container-bg: #2d4638;
            --accent-color: #FAA18F;
            --light-accent: #ffc2b5;
            --light-color: #F5E9D9;
            --text-light: #E8DFD0;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --shadow-color: rgba(15, 25, 19, 0.4);
            --gradient-dark: linear-gradient(135deg, #1a2c22 0%, #2d4638 100%);
            --gradient-light: linear-gradient(135deg, #2d4638 0%, #3d5c49 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: var(--gradient-dark);
            color: var(--text-light);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
        }

        .header-card {
            background: var(--gradient-light);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            box-shadow: 0 10px 30px var(--shadow-color);
            position: relative;
            overflow: hidden;
        }

        .header-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }

        h1 {
            color: var(--text-light);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 2.2rem;
        }

        h1 i {
            color: var(--accent-color);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--accent-color);
            margin-bottom: 0.3rem;
        }

        .stat-label {
            color: rgba(232, 223, 208, 0.7);
            font-size: 0.9rem;
        }

        .btn-back {
            background: transparent;
            border: 2px solid var(--accent-color);
            color: var(--accent-color);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 2rem;
            transition: all 0.3s;
            font-weight: 600;
        }

        .btn-back:hover {
            background: var(--accent-color);
            color: var(--dark-text);
        }

        .order-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .order-card {
            background: var(--gradient-light);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .order-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.2);
        }

        .order-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(250, 161, 143, 0.2);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .order-info {
            display: flex;
            flex-direction: column;
        }

        .order-code {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--accent-color);
        }

        .order-date {
            color: rgba(232, 223, 208, 0.6);
            font-size: 0.9rem;
        }

        .badge-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .status-pending {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .status-processing {
            background: rgba(52, 152, 219, 0.2);
            color: var(--info);
            border: 1px solid var(--info);
        }

        .status-completed {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .status-cancelled {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .payment-badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .payment-paid {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .payment-pending {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .payment-failed {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .order-items {
            margin: 1.5rem 0;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed rgba(250, 161, 143, 0.2);
        }

        .item-name {
            color: var(--text-light);
        }

        .item-quantity {
            color: var(--accent-color);
            font-weight: bold;
        }

        .item-price {
            color: var(--text-light);
        }

        .order-total {
            text-align: right;
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--accent-color);
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid rgba(250, 161, 143, 0.3);
        }

        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(250, 161, 143, 0.2);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .view-details {
            background: var(--accent-color);
            color: var(--dark-text);
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .view-details:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(250, 161, 143, 0.4);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--gradient-light);
            border-radius: 20px;
            border: 2px dashed var(--accent-color);
        }

        .empty-state i {
            font-size: 4rem;
            color: rgba(250, 161, 143, 0.3);
            margin-bottom: 1.5rem;
        }

        .empty-state h3 {
            font-size: 1.8rem;
            margin-bottom: 1rem;
            color: var(--text-light);
        }

        .empty-state p {
            color: rgba(232, 223, 208, 0.7);
            margin-bottom: 2rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-color), var(--light-accent));
            color: var(--dark-text);
            padding: 1rem 2rem;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
        }

        @media (max-width: 768px) {
            .order-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .order-footer {
                flex-direction: column;
                align-items: stretch;
            }
            
            .view-details {
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Card -->
        <div class="header-card">
            <a href="home.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Home</a>
            <h1><i class="fas fa-history"></i> Order History</h1>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_orders; ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">RM <?php echo number_format($total_spent, 2); ?></div>
                    <div class="stat-label">Total Spent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $pending_orders; ?></div>
                    <div class="stat-label">Pending Orders</div>
                </div>
            </div>
        </div>

        <!-- Orders List -->
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <i class="fas fa-shopping-bag"></i>
                <h3>No orders yet</h3>
                <p>Your order history will appear here after you make a purchase.</p>
                <a href="menu.php" class="btn-primary">
                    <i class="fas fa-utensils"></i> Browse Menu
                </a>
            </div>
        <?php else: ?>
            <div class="order-list">
                <?php foreach ($orders as $order): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div class="order-info">
                                <!-- GUNA ORDER_CODE BUKAN ORDER_NUMBER -->
                                <div class="order-code"><?php echo htmlspecialchars($order['order_code'] ?? 'N/A'); ?></div>
                                <div class="order-date">
                                    <?php 
                                    // Check which date column exists
                                    if (!empty($order['order_date'])) {
                                        echo date('d M Y, h:i A', strtotime($order['order_date']));
                                    } elseif (!empty($order['created_at'])) {
                                        echo date('d M Y, h:i A', strtotime($order['created_at']));
                                    } else {
                                        echo 'Date not available';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="badge-group">
                                <span class="status-badge status-<?php echo $order['status'] ?? 'pending'; ?>">
                                    <?php echo ucfirst($order['status'] ?? 'pending'); ?>
                                </span>
                                <span class="payment-badge payment-<?php echo $order['payment_status'] ?? 'pending'; ?>">
                                    <?php echo ucfirst($order['payment_status'] ?? 'pending'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Order Items -->
                        <div class="order-items">
                            <?php 
                            if (!empty($order['items'])):
                                $item_count = 0;
                                foreach ($order['items'] as $item): 
                                    if ($item_count >= 3) break;
                            ?>
                                <div class="item-row">
                                    <span class="item-name"><?php echo htmlspecialchars($item['item_name'] ?? 'Item'); ?></span>
                                    <span class="item-quantity">x<?php echo $item['quantity'] ?? 1; ?></span>
                                    <span class="item-price">RM <?php echo number_format(($item['price'] ?? 0) * ($item['quantity'] ?? 1), 2); ?></span>
                                </div>
                            <?php 
                                    $item_count++;
                                endforeach; 
                                
                                if (count($order['items']) > 3):
                            ?>
                                <div style="color: var(--accent-color); font-size: 0.9rem; margin-top: 0.5rem;">
                                    +<?php echo count($order['items']) - 3; ?> more items
                                </div>
                            <?php 
                                endif;
                            else:
                            ?>
                                <div style="color: rgba(232,223,208,0.5); text-align: center; padding: 1rem;">
                                    No items details available
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Order Total -->
                        <div class="order-total">
                            Total: RM <?php echo number_format($order['total_amount'] ?? 0, 2); ?>
                        </div>
                        
                        <!-- Footer -->
                        <div class="order-footer">
                            <span style="color: rgba(232, 223, 208, 0.6);">
                                <i class="fas fa-credit-card"></i> <?php echo ucfirst($order['payment_method'] ?? 'cash'); ?>
                            </span>
                            <!-- PASTIKAN LINK INI BETUL -->
                            <a href="order_details.php?id=<?php echo $order['id']; ?>" class="view-details">
                                View Details <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Auto-refresh every 30 seconds to update status -->
    <script>
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
