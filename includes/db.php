<?php

// Database credentials
$host = 'localhost';
$dbname = 'yadangs_db';
$username = 'root';
$password = '';

try {
    // Create PDO connection with UTF-8
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
    
    // Set timezone to Malaysia
    $pdo->exec("SET time_zone = '+08:00'");
    
} catch (PDOException $e) {
    // Log error untuk admin
    error_log("Database Connection Error: " . $e->getMessage());
    
    // Show user-friendly message
    die("
        <div style='
            background: #1a2c22;
            color: #FAA18F;
            padding: 20px;
            border-radius: 10px;
            margin: 20px;
            font-family: Arial, sans-serif;
            border: 2px solid #FAA18F;
        '>
            <h2>🔌 Database Connection Error</h2>
            <p>Unable to connect to database. Please check:</p>
            <ul>
                <li>✅ XAMPP MySQL is running</li>
                <li>✅ Database 'yadangs_db' exists</li>
                <li>✅ Connection settings are correct</li>
            </ul>
            <p style='color: #E8DFD0; margin-top: 10px;'>
                <i class='fas fa-info-circle'></i> 
                Please contact administrator.
            </p>
        </div>
    ");
}

/**
 * Get user by ID
 */
function getUserById($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("getUserById Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get user by phone number
 */
function getUserByPhone($pdo, $phone) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ?");
        $stmt->execute([$phone]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("getUserByPhone Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Create new user
 */
function createUser($pdo, $phone, $nickname) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO customers (phone, nickname, created_at, last_login) 
            VALUES (?, ?, NOW(), NOW())
        ");
        $stmt->execute([$phone, $nickname]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("createUser Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update user last login
 */
function updateUserLogin($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE customers SET last_login = NOW() WHERE id = ?");
        return $stmt->execute([$user_id]);
    } catch (PDOException $e) {
        error_log("updateUserLogin Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create new order
 */
function createOrder($pdo, $order_data) {
    try {
        $sql = "INSERT INTO orders (
            order_number, user_id, customer_name, customer_phone, customer_email,
            delivery_address, special_instructions, subtotal, delivery_fee,
            tax_amount, discount_amount, total_amount, payment_method, payment_status, status
        ) VALUES (
            :order_number, :user_id, :customer_name, :customer_phone, :customer_email,
            :delivery_address, :special_instructions, :subtotal, :delivery_fee,
            :tax_amount, :discount_amount, :total_amount, :payment_method, :payment_status, :status
        )";
        
        $stmt = $pdo->prepare($sql);
        
        // Add status to order_data if not present
        if (!isset($order_data['status'])) {
            $order_data['status'] = 'pending';
        }
        if (!isset($order_data['payment_status'])) {
            $order_data['payment_status'] = 'pending';
        }
        
        $stmt->execute($order_data);
        return $pdo->lastInsertId();
        
    } catch (PDOException $e) {
        error_log("createOrder Error: " . $e->getMessage());
        error_log("Order Data: " . print_r($order_data, true));
        return false;
    }
}

/**
 * Add order items
 */
function addOrderItems($pdo, $order_id, $items) {
    try {
        // FIXED: Changed table name from order_items to orderitems
        $sql = "INSERT INTO order_items (
            order_id, item_id, item_name, quantity, price, subtotal, customizations
        ) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        foreach ($items as $item) {
            $stmt->execute([
                $order_id,
                $item['id'],
                $item['name'],
                $item['quantity'],
                $item['price'],
                $item['subtotal'],
                json_encode($item['customizations'] ?? [])
            ]);
        }
        
        return true;
        
    } catch (PDOException $e) {
        error_log("addOrderItems Error: " . $e->getMessage());
        error_log("Items Data: " . print_r($items, true));
        return false;
    }
}

/**
 * Get order by ID
 */
function getOrderById($pdo, $order_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("getOrderById Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get order by order number
 */
function getOrderByNumber($pdo, $order_number) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
        $stmt->execute([$order_number]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("getOrderByNumber Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get order items
 */
function getOrderItems($pdo, $order_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getOrderItems Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user orders
 */
function getUserOrders($pdo, $user_id, $limit = 10) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM orders 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getUserOrders Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Update order with ToyyibPay billcode
 */
function updateOrderBillcode($pdo, $order_id, $billcode) {
    try {
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET toyyibpay_billcode = ? 
            WHERE id = ?
        ");
        return $stmt->execute([$billcode, $order_id]);
    } catch (PDOException $e) {
        error_log("updateOrderBillcode Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update payment status after ToyyibPay callback
 */
function updatePaymentStatus($pdo, $order_id, $status, $transaction_id = null) {
    try {
        $sql = "UPDATE orders SET 
                payment_status = ?,
                payment_date = NOW()";
        
        $params = [$status];
        
        if ($transaction_id) {
            $sql .= ", payment_reference = ?, toyyibpay_transaction_id = ?";
            $params[] = $transaction_id;
            $params[] = $transaction_id;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $order_id;
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
        
    } catch (PDOException $e) {
        error_log("updatePaymentStatus Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log payment transaction
 */
function logPayment($pdo, $order_id, $billcode, $transaction_data, $status) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO payment_logs (order_id, billcode, transaction_data, status) 
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([
            $order_id,
            $billcode,
            is_array($transaction_data) ? json_encode($transaction_data) : $transaction_data,
            $status
        ]);
    } catch (PDOException $e) {
        error_log("logPayment Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all available menu items
 */
function getMenuItems($pdo, $category = null) {
    try {
        if ($category) {
            $stmt = $pdo->prepare("
                SELECT * FROM menu_items 
                WHERE is_available = 1 AND category = ? 
                ORDER BY name
            ");
            $stmt->execute([$category]);
        } else {
            $stmt = $pdo->query("
                SELECT * FROM menu_items 
                WHERE is_available = 1 
                ORDER BY category, name
            ");
        }
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getMenuItems Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get menu item by ID
 */
function getMenuItemById($pdo, $item_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
        $stmt->execute([$item_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("getMenuItemById Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get user favorites
 */
function getUserFavorites($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT f.*, m.name, m.price, m.image_url, m.category 
            FROM favorites f
            JOIN menu_items m ON f.item_id = m.id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getUserFavorites Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Add to favorites
 */
function addToFavorites($pdo, $user_id, $item_id, $notes = '') {
    try {
        // Check if already exists
        $stmt = $pdo->prepare("SELECT id FROM favorites WHERE user_id = ? AND item_id = ?");
        $stmt->execute([$user_id, $item_id]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO favorites (user_id, item_id, notes) 
                VALUES (?, ?, ?)
            ");
            return $stmt->execute([$user_id, $item_id, $notes]);
        }
        return false; // Already exists
    } catch (PDOException $e) {
        error_log("addToFavorites Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove from favorites
 */
function removeFromFavorites($pdo, $user_id, $item_id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND item_id = ?");
        return $stmt->execute([$user_id, $item_id]);
    } catch (PDOException $e) {
        error_log("removeFromFavorites Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate unique order number
 */
function generateOrderNumber($pdo) {
    $date = date('Ymd');
    $prefix = 'ORD-' . $date . '-';
    
    try {
        $stmt = $pdo->prepare("
            SELECT order_number FROM orders 
            WHERE order_number LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetch();
        
        if ($last) {
            $last_num = intval(substr($last['order_number'], -4));
            $new_num = str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $new_num = '0001';
        }
        
        return $prefix . $new_num;
        
    } catch (PDOException $e) {
        error_log("generateOrderNumber Error: " . $e->getMessage());
        return $prefix . '0001'; // Fallback
    }
}

/**
 * Format currency
 */
function formatMoney($amount) {
    return 'RM ' . number_format($amount, 2);
}

/**
 * Get cart count from session
 */
function getCartCount() {
    if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        return array_sum(array_column($_SESSION['cart'], 'quantity'));
    }
    return 0;
}

/**
 * Calculate cart totals
 */
function calculateCartTotals($cart) {
    $subtotal = 0;
    $delivery_fee = isset($_SESSION['free_shipping']) ? 0 : 3.00;
    $tax_rate = 0.06;
    
    foreach ($cart as $item) {
        $price = floatval($item['price'] ?? 0);
        $quantity = intval($item['quantity'] ?? 1);
        
        // Add customization charges
        if (isset($item['customizations']['milk']) && $item['customizations']['milk'] === 'oat') {
            $price += 2.00;
        }
        if (isset($item['customizations']['syrup']) && $item['customizations']['syrup'] !== 'none') {
            $price += 1.00;
        }
        
        $subtotal += $price * $quantity;
    }
    
    $tax = $subtotal * $tax_rate;
    $discount = isset($_SESSION['discount']) ? $subtotal * $_SESSION['discount'] : 0;
    $total = $subtotal + $delivery_fee + $tax - $discount;
    
    return [
        'subtotal' => $subtotal,
        'delivery_fee' => $delivery_fee,
        'tax' => $tax,
        'discount' => $discount,
        'total' => $total
    ];
}

?>
