<?php
session_start();
require_once 'includes/db.php';

// Error reporting untuk debugging (boleh disable dalam production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Function to connect to database for profile pic
function connectDB() {
    $host = 'localhost';
    $dbname = 'yadangs_db';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Profile DB Connection Error: " . $e->getMessage());
        return null;
    }
}

// User data dari session
$user_id = $_SESSION['user_id'] ?? 0;
$nickname = htmlspecialchars($_SESSION['nickname'] ?? 'Guest');
$phone = htmlspecialchars($_SESSION['phone'] ?? '');
$email = htmlspecialchars($_SESSION['email'] ?? '');

// Fetch profile picture
$profile_pic = null;
$db = connectDB();
if ($db) {
    try {
        $stmt = $db->prepare("SELECT profile_picture FROM user_profiles WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && !empty($result['profile_picture'])) {
            $profile_path = 'uploads/profile_pics/' . $result['profile_picture'];
            if (file_exists($profile_path)) {
                $profile_pic = $profile_path . '?t=' . filemtime($profile_path);
            }
        }
    } catch (PDOException $e) {
        error_log("Profile Pic Error: " . $e->getMessage());
    }
}

// Initialize cart from session
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Default image path
$default_image = 'images/default-drink.jpg';
$upload_dir = 'uploads/menu_items/';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];
    
    try {
        if ($action == 'add_to_cart') {
            $item_id = $_POST['item_id'];
            $name = $_POST['name'];
            $price = floatval($_POST['price']);
            
            // Get image from database
            try {
                $stmt = $pdo->prepare("SELECT image_url FROM menu_items WHERE id = ?");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch();
                $image_url = $item ? $item['image_url'] : '';
            } catch (PDOException $e) {
                $image_url = '';
                error_log("Add to cart image fetch error: " . $e->getMessage());
            }
            
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
            
            $customizations = [
                'sweetness' => '100%',
                'milk' => 'regular',
                'syrup' => 'none'
            ];
            
            if (isset($_SESSION['cart'][$item_id])) {
                $_SESSION['cart'][$item_id]['quantity']++;
            } else {
                $_SESSION['cart'][$item_id] = [
                    'name' => $name,
                    'price' => $price,
                    'quantity' => 1,
                    'customizations' => $customizations,
                    'image' => $image_url
                ];
            }
            
            $response = ['success' => true, 'message' => 'Added to cart!'];
            
        } elseif ($action == 'get_cart_count') {
            $count = 0;
            if (isset($_SESSION['cart'])) {
                foreach ($_SESSION['cart'] as $item) {
                    $count += $item['quantity'] ?? 0;
                }
            }
            $response = ['success' => true, 'count' => $count];
            
        } elseif ($action == 'update_quantity') {
            $item_id = $_POST['item_id'];
            $quantity = intval($_POST['quantity']);
            
            if (isset($_SESSION['cart'][$item_id])) {
                if ($quantity > 0) {
                    $_SESSION['cart'][$item_id]['quantity'] = $quantity;
                    $response = ['success' => true, 'message' => 'Quantity updated'];
                } else {
                    unset($_SESSION['cart'][$item_id]);
                    $response = ['success' => true, 'message' => 'Item removed'];
                }
            }
            
        } elseif ($action == 'update_customization') {
            $item_id = $_POST['item_id'];
            $type = $_POST['customization_type'];
            $value = $_POST['customization_value'];
            
            if (isset($_SESSION['cart'][$item_id])) {
                if (!isset($_SESSION['cart'][$item_id]['customizations'])) {
                    $_SESSION['cart'][$item_id]['customizations'] = [];
                }
                $_SESSION['cart'][$item_id]['customizations'][$type] = $value;
                $response = ['success' => true, 'message' => 'Customization updated'];
            }
            
        } elseif ($action == 'remove_item') {
            $item_id = $_POST['item_id'];
            if (isset($_SESSION['cart'][$item_id])) {
                unset($_SESSION['cart'][$item_id]);
                $response = ['success' => true, 'message' => 'Item removed'];
            }
            
        } elseif ($action == 'clear_cart') {
            $_SESSION['cart'] = [];
            $response = ['success' => true, 'message' => 'Cart cleared'];
            
        } elseif ($action == 'apply_promo') {
            $promo_code = $_POST['promo_code'] ?? '';
            if (strtoupper($promo_code) == 'WELCOME10') {
                $_SESSION['discount'] = 0.10;
                $response = ['success' => true, 'message' => 'Promo applied! 10% discount', 'discount' => 10];
            } elseif (strtoupper($promo_code) == 'FREESHIP') {
                $_SESSION['free_shipping'] = true;
                $response = ['success' => true, 'message' => 'Free shipping applied!'];
            } else {
                $response = ['success' => false, 'message' => 'Invalid promo code'];
            }
        }
        
        // Recalculate totals for all actions except get_cart_count
        if ($action != 'get_cart_count') {
            $delivery_fee = isset($_SESSION['free_shipping']) ? 0 : 3.00;
            $tax_rate = 0.06;
            $new_subtotal = 0;
            
            foreach ($_SESSION['cart'] as $id => $item) {
                $base = floatval($item['price'] ?? 0);
                $extra = 0;
                if (isset($item['customizations']['milk']) && $item['customizations']['milk'] === 'oat') {
                    $extra += 2.00;
                }
                if (isset($item['customizations']['syrup']) && $item['customizations']['syrup'] !== 'none') {
                    $extra += 1.00;
                }
                $new_subtotal += ($base + $extra) * intval($item['quantity'] ?? 0);
            }
            
            $new_tax = $new_subtotal * $tax_rate;
            $discount = isset($_SESSION['discount']) ? $new_subtotal * $_SESSION['discount'] : 0;
            $new_total = $new_subtotal + $new_tax + $delivery_fee - $discount;
            
            $response['totals'] = [
                'subtotal' => number_format($new_subtotal, 2),
                'tax' => number_format($new_tax, 2),
                'delivery' => number_format($delivery_fee, 2),
                'discount' => number_format($discount, 2),
                'total' => number_format($new_total, 2),
                'item_count' => array_sum(array_column($_SESSION['cart'], 'quantity'))
            ];
        }
        
    } catch (Exception $e) {
        error_log("AJAX Error in cart.php: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'An error occurred'];
    }
    
    echo json_encode($response);
    exit();
}

// Process cart items for display
$cart = $_SESSION['cart'] ?? [];
$cart_items = [];
$subtotal = 0;
$delivery_fee = isset($_SESSION['free_shipping']) ? 0 : 3.00;
$tax_rate = 0.06;

$add_ons_pricing = [
    'oat_milk' => 2.00,
    'vanilla_syrup' => 1.00,
    'caramel_syrup' => 1.00,
    'hazelnut_syrup' => 1.00
];

foreach ($cart as $item_id => $item) {
    if (is_array($item)) {
        $base_price = floatval($item['price'] ?? 0);
        $customizations = $item['customizations'] ?? [];
        $quantity = intval($item['quantity'] ?? 1);
        
        $extra_charge = 0;
        if (isset($customizations['milk']) && $customizations['milk'] === 'oat') {
            $extra_charge += $add_ons_pricing['oat_milk'];
        }
        if (isset($customizations['syrup']) && $customizations['syrup'] !== 'none') {
            $extra_charge += $add_ons_pricing[$customizations['syrup'] . '_syrup'] ?? 1.00;
        }
        
        $final_price = $base_price + $extra_charge;
        
        // Fix image path - check if image exists
        $image_path = $default_image;
        if (!empty($item['image'])) {
            $full_path = $upload_dir . $item['image'];
            if (file_exists($full_path)) {
                $image_path = $full_path . '?t=' . filemtime($full_path);
            }
        }
        
        $cart_items[] = [
            'id' => $item_id,
            'name' => htmlspecialchars($item['name'] ?? 'Unknown Item'),
            'base_price' => $base_price,
            'price' => $final_price,
            'extra_charge' => $extra_charge,
            'quantity' => $quantity,
            'customizations' => $customizations,
            'image' => $image_path
        ];
        $subtotal += $final_price * $quantity;
    }
}

$tax_amount = $subtotal * $tax_rate;
$discount_amount = isset($_SESSION['discount']) ? $subtotal * $_SESSION['discount'] : 0;
$total = $subtotal + $delivery_fee + $tax_amount - $discount_amount;

// Get recommended items
$recommended_items = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM menu_items 
        WHERE is_available = 1 
        ORDER BY RAND() 
        LIMIT 4
    ");
    $recommended_items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Recommended items error: " . $e->getMessage());
}

// Get cart count
$cart_count = array_sum(array_column($_SESSION['cart'], 'quantity'));

// Handle error messages from checkout
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
$payment_message = $_GET['message'] ?? '';

$error_message = '';
$success_message = '';

if ($error == 'payment_failed') {
    $error_message = 'Payment failed. ' . htmlspecialchars($payment_message);
} elseif ($error == 'checkout_failed') {
    $error_message = 'Checkout failed. Please try again.';
} elseif ($error == 'empty_cart') {
    $error_message = 'Your cart is empty.';
} elseif ($success == 'order_placed') {
    $success_message = 'Order placed successfully! Thank you.';
}

// Display session errors
if (isset($_SESSION['checkout_errors']) && is_array($_SESSION['checkout_errors'])) {
    $error_message = implode('<br>', $_SESSION['checkout_errors']);
    unset($_SESSION['checkout_errors']);
}

if (isset($_SESSION['payment_error'])) {
    $error_message = 'Payment Error: ' . $_SESSION['payment_error'];
    unset($_SESSION['payment_error']);
}

if (isset($_SESSION['checkout_error'])) {
    $error_message = $_SESSION['checkout_error'];
    unset($_SESSION['checkout_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart | Yadang's Time</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== DARK GREEN THEME ===== */
        :root {
            --dark-bg: #1a2c22;
            --container-bg: #2d4638;
            --accent-color: #FAA18F;
            --light-accent: #ffc2b5;
            --light-color: #F5E9D9;
            --dark-text: #0f1913;
            --text-light: #E8DFD0;
            --card-bg: rgba(61, 92, 73, 0.85);
            --shadow-color: rgba(15, 25, 19, 0.4);
            --heart-color: #ff6b6b;
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
            line-height: 1.7;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }
        
        /* ===== BACKGROUND ELEMENTS ===== */
        .bg-pattern {
            position: fixed;
            width: 100%;
            height: 100%;
            opacity: 0.03;
            background-image: 
                radial-gradient(circle at 20% 80%, var(--accent-color) 1px, transparent 1px),
                radial-gradient(circle at 80% 20%, var(--accent-color) 1px, transparent 1px);
            background-size: 60px 60px;
            z-index: -1;
        }
        
        .bg-leaf {
            position: fixed;
            font-size: 20rem;
            color: rgba(250, 161, 143, 0.03);
            z-index: -1;
            pointer-events: none;
        }
        
        .leaf-1 {
            top: -80px;
            right: -80px;
            transform: rotate(45deg);
        }
        
        .leaf-2 {
            bottom: -100px;
            left: -100px;
            transform: rotate(-45deg);
        }
        
        /* ===== HEADER ===== */
        .main-header {
            background: rgba(45, 70, 56, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 3px solid rgba(250, 161, 143, 0.3);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 4px 20px var(--shadow-color);
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo-circle {
            width: 60px;
            height: 60px;
            background: var(--card-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--accent-color);
            padding: 8px;
        }
        
        .logo-circle img {
            width: 40px;
            height: 40px;
            filter: brightness(1.2);
        }
        
        .brand-text h1 {
            font-size: 1.8rem;
            color: var(--text-light);
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        .brand-text p {
            color: var(--accent-color);
            font-size: 0.9rem;
            font-style: italic;
            letter-spacing: 2px;
        }
        
        /* ===== USER INFO ===== */
        .user-profile {
            background: rgba(250, 161, 143, 0.15);
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            border: 2px solid rgba(250, 161, 143, 0.3);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        
        .user-profile:hover {
            background: rgba(250, 161, 143, 0.25);
            transform: translateY(-2px);
            border-color: var(--accent-color);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: var(--accent-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: var(--dark-text);
            overflow: hidden;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-light);
        }
        
        .user-phone {
            font-size: 0.85rem;
            color: var(--accent-color);
            font-family: monospace;
        }
        
        /* ===== USER MENU ===== */
        #userMenu {
            display: none;
            position: absolute;
            top: calc(100% + 10px);
            right: 2rem;
            background: var(--container-bg);
            border: 2px solid var(--accent-color);
            border-radius: 10px;
            padding: 0.5rem;
            min-width: 200px;
            z-index: 1001;
            box-shadow: 0 5px 20px var(--shadow-color);
        }
        
        #userMenu a {
            display: block;
            padding: 0.8rem 1rem;
            color: var(--text-light);
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        #userMenu a:hover {
            background: rgba(250, 161, 143, 0.2);
            color: var(--accent-color);
        }
        
        /* ===== NAVIGATION ===== */
        .main-nav {
            width: 100%;
            margin-top: 1rem;
        }
        
        .nav-container {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .nav-item {
            color: var(--text-light);
            text-decoration: none;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-size: 1rem;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            background: rgba(61, 92, 73, 0.5);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        
        .nav-item:hover {
            background: var(--accent-color);
            color: var(--dark-text);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(250, 161, 143, 0.3);
            border-color: var(--accent-color);
        }
        
        .nav-item.active {
            background: var(--accent-color);
            color: var(--dark-text);
            border-color: var(--accent-color);
        }
        
        .cart-count {
            background: var(--accent-color);
            color: var(--dark-text);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 5px;
        }
        
        /* ===== TOAST NOTIFICATION ===== */
        .toast-container {
            position: fixed;
            top: 100px;
            right: 2rem;
            z-index: 2000;
            max-width: 350px;
        }
        
        .toast {
            background: var(--accent-color);
            color: var(--dark-text);
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideInRight 0.3s ease;
            box-shadow: 0 5px 20px rgba(250, 161, 143, 0.4);
            border-left: 5px solid var(--dark-text);
        }
        
        .toast.error {
            background: #e74c3c;
            color: white;
            border-left-color: #c0392b;
        }
        
        .toast.success {
            background: #2ecc71;
            color: white;
            border-left-color: #27ae60;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        /* ===== MAIN CONTAINER ===== */
        .cart-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 2rem;
        }
        
        @media (max-width: 900px) {
            .cart-container {
                grid-template-columns: 1fr;
            }
        }
        
        /* ===== CART ITEMS SECTION ===== */
        .cart-items-section {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 30px var(--shadow-color);
            border: 1px solid rgba(250, 161, 143, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .cart-items-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .section-title {
            color: var(--text-light);
            font-size: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .section-title i {
            color: var(--accent-color);
        }
        
        .cart-item {
            display: flex;
            gap: 1.5rem;
            padding: 1.5rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 15px;
            margin-bottom: 1rem;
            border: 1px solid rgba(250, 161, 143, 0.1);
            transition: all 0.3s ease;
            animation: fadeIn 0.5s ease-out;
        }
        
        .cart-item:hover {
            border-color: var(--accent-color);
            transform: translateX(5px);
        }
        
        .item-image {
            width: 100px;
            height: 100px;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
            border: 2px solid rgba(250, 161, 143, 0.2);
            background: var(--dark-bg);
        }
        
        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .item-details {
            flex: 1;
        }
        
        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }
        
        .item-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-light);
        }
        
        .item-price {
            color: var(--accent-color);
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .add-ons-section {
            margin: 1rem 0;
            padding: 1rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 10px;
        }
        
        .add-ons-title {
            font-size: 0.9rem;
            color: var(--accent-color);
            margin-bottom: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .customization-select {
            width: 100%;
            padding: 0.8rem;
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid rgba(250, 161, 143, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
        }
        
        .customization-select:hover {
            border-color: var(--accent-color);
        }
        
        .extra-charge {
            color: #2ecc71;
            font-size: 0.8rem;
            margin-left: 5px;
        }
        
        .item-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .quantity-control {
            display: flex;
            align-items: center;
            background: rgba(26, 44, 34, 0.7);
            border-radius: 25px;
            border: 1px solid rgba(250, 161, 143, 0.3);
            overflow: hidden;
        }
        
        .qty-btn {
            background: transparent;
            border: none;
            color: var(--text-light);
            width: 35px;
            height: 35px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .qty-btn:hover {
            background: rgba(250, 161, 143, 0.2);
        }
        
        .qty-display {
            width: 40px;
            text-align: center;
            font-weight: bold;
        }
        
        .remove-btn {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
            border: 1px solid #e74c3c;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }
        
        .remove-btn:hover {
            background: #e74c3c;
            color: white;
        }
        
        .empty-cart {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-cart i {
            font-size: 4rem;
            color: rgba(250, 161, 143, 0.3);
            margin-bottom: 1.5rem;
        }
        
        .empty-cart h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .empty-cart p {
            color: rgba(232, 223, 208, 0.7);
            margin-bottom: 1.5rem;
        }
        
        .shop-now-btn {
            background: linear-gradient(135deg, var(--accent-color), var(--light-accent));
            color: var(--dark-text);
            padding: 1rem 2rem;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .shop-now-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
        }
        
        /* ===== ORDER SUMMARY SECTION ===== */
        .order-summary {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 30px var(--shadow-color);
            border: 1px solid rgba(250, 161, 143, 0.2);
            position: sticky;
            top: 120px;
            height: fit-content;
        }
        
        .order-summary::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .summary-title {
            color: var(--text-light);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding: 0.5rem 0;
            color: rgba(232, 223, 208, 0.9);
            font-size: 1rem;
        }
        
        .summary-row.total {
            border-top: 2px solid rgba(250, 161, 143, 0.3);
            margin-top: 1rem;
            padding-top: 1rem;
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--accent-color);
        }
        
        .promo-section {
            margin: 1.5rem 0;
        }
        
        .promo-input {
            display: flex;
            gap: 0.5rem;
        }
        
        .promo-input input {
            flex: 1;
            padding: 0.8rem;
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid rgba(250, 161, 143, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 1rem;
        }
        
        .promo-input input:focus {
            outline: none;
            border-color: var(--accent-color);
        }
        
        .apply-promo {
            background: var(--accent-color);
            color: var(--dark-text);
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .apply-promo:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(250, 161, 143, 0.3);
        }
        
        /* ===== CHECKOUT FORM ===== */
        .checkout-form {
            margin-top: 2rem;
            padding: 1.5rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 15px;
            border: 1px solid var(--accent-color);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            color: var(--accent-color);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 0.8rem;
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid rgba(250, 161, 143, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 1rem;
        }
        
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent-color);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .payment-warning {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid #e74c3c;
            color: #e74c3c;
            padding: 0.8rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: none;
        }
        
        .payment-warning i {
            margin-right: 5px;
        }
        
        .place-order-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--accent-color), var(--light-accent));
            color: var(--dark-text);
            border: none;
            padding: 1rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .place-order-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
        }
        
        .place-order-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* ===== RECOMMENDED ITEMS ===== */
        .recommended-section {
            margin-top: 2rem;
        }
        
        .recommended-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .recommended-item {
            background: rgba(26, 44, 34, 0.5);
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            border: 1px solid rgba(250, 161, 143, 0.1);
            transition: all 0.3s ease;
        }
        
        .recommended-item:hover {
            border-color: var(--accent-color);
            transform: translateY(-3px);
        }
        
        .recommended-item img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 0.5rem;
            border: 2px solid var(--accent-color);
        }
        
        .recommended-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.3rem;
        }
        
        .recommended-price {
            color: var(--accent-color);
            font-weight: bold;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        
        /* ===== UBAH BUTTON ADD - SEKARANG GUNA AJAX ===== */
        .add-recommended {
            background: linear-gradient(135deg, var(--accent-color), var(--light-accent));
            color: var(--dark-text);
            border: none;
            width: 100%;
            padding: 0.5rem;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .add-recommended:hover {
            transform: scale(1.02);
            box-shadow: 0 5px 10px rgba(250, 161, 143, 0.3);
        }
        
        .add-recommended:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        /* ===== LOADING SPINNER ===== */
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: var(--accent-color);
            animation: spin 1s ease-in-out infinite;
            margin-right: 5px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* ===== FOOTER ===== */
        .main-footer {
            background: linear-gradient(135deg, #1a2c22, #0f1913);
            color: var(--text-light);
            padding: 2rem;
            margin-top: 4rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .main-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .copyright {
            color: rgba(245, 233, 217, 0.6);
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        .secure-badge {
            text-align: center;
            color: rgba(232, 223, 208, 0.6);
            font-size: 0.9rem;
            margin-top: 1rem;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-header {
                flex-direction: column;
                text-align: center;
                padding: 1rem;
            }
            
            .header-left {
                flex-direction: column;
                text-align: center;
            }
            
            .user-profile {
                margin-top: 1rem;
            }
            
            .nav-container {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .nav-item {
                width: 100%;
                text-align: center;
                justify-content: center;
            }
            
            .cart-container {
                padding: 0 1rem;
            }
            
            .cart-item {
                flex-direction: column;
            }
            
            .item-image {
                width: 100%;
                height: 150px;
            }
            
            .item-actions {
                flex-wrap: wrap;
            }
            
            .recommended-grid {
                grid-template-columns: 1fr;
            }
            
            #userMenu {
                right: 1rem;
                left: 1rem;
                width: auto;
            }
            
            .toast-container {
                right: 1rem;
                left: 1rem;
                max-width: none;
            }
        }
        
        @media (max-width: 480px) {
            .logo-circle {
                width: 50px;
                height: 50px;
            }
            
            .logo-circle img {
                width: 30px;
                height: 30px;
            }
            
            .brand-text h1 {
                font-size: 1.5rem;
            }
            
            .user-profile {
                padding: 0.6rem 1rem;
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
            }
            
            .nav-item span {
                display: none;
            }
            
            .nav-item i {
                font-size: 1rem;
            }
            
            .cart-items-section {
                padding: 1.5rem;
            }
            
            .order-summary {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Background Elements -->
    <div class="bg-pattern"></div>
    <div class="bg-leaf leaf-1">
        <i class="fas fa-leaf"></i>
    </div>
    <div class="bg-leaf leaf-2">
        <i class="fas fa-leaf"></i>
    </div>
    
    <!-- Toast Notifications -->
    <?php if (!empty($error_message)): ?>
    <div class="toast-container">
        <div class="toast error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo $error_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($success_message)): ?>
    <div class="toast-container">
        <div class="toast success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo $success_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <header class="main-header">
        <div class="header-left">
            <div class="logo-container">
                <div class="logo-circle">
                    <img src="images/yadangs_logo.png" alt="Yadang's Time Logo">
                </div>
                <div class="brand-text">
                    <h1>YADANG'S TIME</h1>
                    <p>more brews, less blues</p>
                </div>
            </div>
        </div>
        
        <div class="user-profile" onclick="toggleUserMenu()">
            <div class="user-avatar">
                <?php if ($profile_pic): ?>
                    <img src="<?php echo $profile_pic; ?>" alt="Profile Picture">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            
            <div class="user-details">
                <div class="user-name">Welcome, <?php echo $nickname; ?>!</div>
                <div class="user-phone">📱 <?php echo $phone; ?></div>
            </div>
        </div>
        
        <!-- User Menu -->
        <div id="userMenu">
            <a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a>
            <a href="order_history.php"><i class="fas fa-history"></i> Orders</a>
        </div>
    </header>
    
    <!-- Navigation -->
    <nav class="main-nav" aria-label="Main Navigation">
        <div class="nav-container">
            <a href="home.php" class="nav-item">
                <i class="fas fa-home"></i> <span>Home</span>
            </a>
            <a href="menu.php" class="nav-item">
                <i class="fas fa-coffee"></i> <span>Menu</span>
            </a>
            <a href="cart.php" class="nav-item active">
                <i class="fas fa-shopping-cart"></i> <span>Cart</span>
                <span class="cart-count" id="cartCount"><?php echo $cart_count; ?></span>
            </a>
            <a href="favorites.php" class="nav-item">
                <i class="fas fa-heart"></i> <span>Favorites</span>
            </a>
            <a href="feedback.php" class="nav-item">
                <i class="fas fa-comment-dots"></i> <span>Feedback</span>
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a>
        </div>
    </nav>
    
    <!-- Main Cart Container -->
    <div class="cart-container">
        <!-- Cart Items Section -->
        <div class="cart-items-section">
            <h2 class="section-title">
                <i class="fas fa-shopping-cart"></i> Your Cart
                <span style="margin-left: auto; font-size: 1rem; color: var(--accent-color);" id="itemCount">
                    <?php echo count($cart_items); ?> item(s)
                </span>
            </h2>
            
            <div id="cartItems">
                <?php if (empty($cart_items)): ?>
                    <div class="empty-cart">
                        <i class="fas fa-shopping-cart"></i>
                        <h3>Your cart is empty</h3>
                        <p>Looks like you haven't added anything yet</p>
                        <a href="menu.php" class="shop-now-btn">
                            <i class="fas fa-utensils"></i> Browse Menu
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($cart_items as $index => $item): ?>
                        <div class="cart-item" data-item-id="<?php echo $item['id']; ?>" style="animation-delay: <?php echo $index * 0.1; ?>s">
                            <div class="item-image">
                                <img src="<?php echo $item['image']; ?>" 
                                     alt="<?php echo $item['name']; ?>"
                                     onerror="this.src='<?php echo $default_image; ?>'">
                            </div>
                            
                            <div class="item-details">
                                <div class="item-header">
                                    <h3 class="item-name"><?php echo $item['name']; ?></h3>
                                    <span class="item-price" id="price-<?php echo $item['id']; ?>">RM <?php echo number_format($item['price'], 2); ?></span>
                                </div>
                                
                                <!-- Add Ons Section -->
                                <div class="add-ons-section">
                                    <div class="add-ons-title">
                                        <i class="fas fa-sliders-h"></i> Customize
                                    </div>
                                    
                                    <select class="customization-select" onchange="updateCustomization(<?php echo $item['id']; ?>, 'sweetness', this.value)">
                                        <option value="100%" <?php echo ($item['customizations']['sweetness'] ?? '100%') == '100%' ? 'selected' : ''; ?>>100% Sugar</option>
                                        <option value="75%" <?php echo ($item['customizations']['sweetness'] ?? '') == '75%' ? 'selected' : ''; ?>>75% Sugar</option>
                                        <option value="50%" <?php echo ($item['customizations']['sweetness'] ?? '') == '50%' ? 'selected' : ''; ?>>50% Sugar</option>
                                        <option value="25%" <?php echo ($item['customizations']['sweetness'] ?? '') == '25%' ? 'selected' : ''; ?>>25% Sugar</option>
                                        <option value="0%" <?php echo ($item['customizations']['sweetness'] ?? '') == '0%' ? 'selected' : ''; ?>>No Sugar</option>
                                    </select>
                                    
                                    <select class="customization-select" onchange="updateCustomization(<?php echo $item['id']; ?>, 'milk', this.value)">
                                        <option value="regular" <?php echo ($item['customizations']['milk'] ?? 'regular') == 'regular' ? 'selected' : ''; ?>>Regular Milk</option>
                                        <option value="oat" <?php echo ($item['customizations']['milk'] ?? '') == 'oat' ? 'selected' : ''; ?>>Oat Milk <span class="extra-charge">(+RM2)</span></option>
                                    </select>
                                    
                                    <select class="customization-select" onchange="updateCustomization(<?php echo $item['id']; ?>, 'syrup', this.value)">
                                        <option value="none" <?php echo ($item['customizations']['syrup'] ?? 'none') == 'none' ? 'selected' : ''; ?>>No Syrup</option>
                                        <option value="vanilla" <?php echo ($item['customizations']['syrup'] ?? '') == 'vanilla' ? 'selected' : ''; ?>>Vanilla <span class="extra-charge">(+RM1)</span></option>
                                        <option value="caramel" <?php echo ($item['customizations']['syrup'] ?? '') == 'caramel' ? 'selected' : ''; ?>>Caramel <span class="extra-charge">(+RM1)</span></option>
                                        <option value="hazelnut" <?php echo ($item['customizations']['syrup'] ?? '') == 'hazelnut' ? 'selected' : ''; ?>>Hazelnut <span class="extra-charge">(+RM1)</span></option>
                                    </select>
                                </div>
                                
                                <div class="item-actions">
                                    <div class="quantity-control">
                                        <button class="qty-btn" onclick="updateQuantity(<?php echo $item['id']; ?>, 'decrease')">-</button>
                                        <span class="qty-display" id="qty-<?php echo $item['id']; ?>"><?php echo $item['quantity']; ?></span>
                                        <button class="qty-btn" onclick="updateQuantity(<?php echo $item['id']; ?>, 'increase')">+</button>
                                    </div>
                                    
                                    <button class="remove-btn" onclick="removeItem(<?php echo $item['id']; ?>)">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="text-align: right; margin-top: 1rem;">
                        <button class="remove-btn" onclick="clearCart()">
                            <i class="fas fa-trash-alt"></i> Clear Cart
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recommended Items - BUTTON ADD SEKARANG GUNA AJAX -->
            <?php if (!empty($recommended_items) && !empty($cart_items)): ?>
                <div class="recommended-section">
                    <h3 class="section-title" style="font-size: 1.3rem;">
                        <i class="fas fa-star"></i> You Might Also Like
                    </h3>
                    
                    <div class="recommended-grid">
                        <?php foreach ($recommended_items as $item): ?>
                            <div class="recommended-item">
                                <img src="<?php 
                                    if (!empty($item['image_url']) && file_exists('uploads/menu_items/' . $item['image_url'])) {
                                        echo 'uploads/menu_items/' . $item['image_url'] . '?t=' . filemtime('uploads/menu_items/' . $item['image_url']);
                                    } else {
                                        echo $default_image;
                                    }
                                ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                                <div class="recommended-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="recommended-price">RM <?php echo number_format($item['price'], 2); ?></div>
                                <!-- BUTTON ADD - SEKARANG PANGGIL addToCartFromRecommended() -->
                                <button class="add-recommended" onclick="addToCartFromRecommended(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>', <?php echo $item['price']; ?>)">
                                    <i class="fas fa-cart-plus"></i> Add to Cart
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Order Summary Section -->
        <div class="order-summary">
            <h2 class="summary-title">Order Summary</h2>
            
            <div class="summary-row">
                <span>Subtotal</span>
                <span id="subtotal">RM <?php echo number_format($subtotal, 2); ?></span>
            </div>
            
            <div class="summary-row">
                <span>Delivery Fee</span>
                <span id="deliveryFee">RM <?php echo number_format($delivery_fee, 2); ?></span>
            </div>
            
            <div class="summary-row">
                <span>Tax (6% SST)</span>
                <span id="tax">RM <?php echo number_format($tax_amount, 2); ?></span>
            </div>
            
            <?php if ($discount_amount > 0): ?>
            <div class="summary-row" style="color: #27ae60;">
                <span>Discount</span>
                <span id="discount">-RM <?php echo number_format($discount_amount, 2); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="summary-row total">
                <span>Total</span>
                <span id="total">RM <?php echo number_format($total, 2); ?></span>
            </div>
            
            <!-- Promo Code -->
            <div class="promo-section">
                <div class="promo-input">
                    <input type="text" id="promoCode" placeholder="Enter promo code (WELCOME10 / FREESHIP)">
                    <button class="apply-promo" onclick="applyPromo()">Apply</button>
                </div>
            </div>
            
            <!-- CHECKOUT FORM -->
            <?php if (!empty($cart_items)): ?>
                <div class="checkout-form">
                    <h3 style="color: var(--accent-color); margin-bottom: 1.5rem;">Checkout Details</h3>
                    
                    <form method="POST" action="checkout.php" id="checkoutForm" onsubmit="return validateForm()">
                        <input type="hidden" name="checkout" value="1">
                        <input type="hidden" name="total_amount" value="<?php echo $total; ?>">
                        
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="customer_name" class="form-input" 
                                   value="<?php echo htmlspecialchars($nickname); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone Number *</label>
                            <input type="tel" name="customer_phone" class="form-input" 
                                   value="<?php echo htmlspecialchars($phone); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="customer_email" class="form-input" 
                                   value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Delivery Address *</label>
                            <textarea name="delivery_address" class="form-textarea" required 
                                      placeholder="Enter your complete address"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Special Instructions</label>
                            <textarea name="special_instructions" class="form-textarea" 
                                      placeholder="E.g., Ring bell, leave at door, etc."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select" id="paymentMethod" required>
                                <option value="cash">Cash on Delivery</option>
                                <option value="online">Online Payment (FPX/Card)</option>
                            </select>
                        </div>
                        
                        <!-- Payment Warning -->
                        <div class="payment-warning" id="paymentWarning">
                            <i class="fas fa-exclamation-triangle"></i>
                            You will be redirected to ToyyibPay payment page after placing order.
                        </div>
                        
                        <button type="submit" class="place-order-btn" id="placeOrderBtn">
                            <i class="fas fa-check-circle"></i> Place Order • RM <?php echo number_format($total, 2); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <div class="secure-badge">
                <i class="fas fa-shield-alt"></i> Secure checkout • Your info is safe
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time. All rights reserved.</p>
            <p style="margin-top: 0.5rem;">Made with ෆ for beverage lovers</p>
        </div>
    </footer>
    
    <script>
        // ===== USER MENU TOGGLE =====
        function toggleUserMenu() {
            const menu = document.getElementById('userMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        
        // Close user menu when clicking outside
        document.addEventListener('click', function(event) {
            const userProfile = document.querySelector('.user-profile');
            const userMenu = document.getElementById('userMenu');
            
            if (userMenu && userMenu.style.display === 'block' && 
                !userProfile.contains(event.target) && !userMenu.contains(event.target)) {
                userMenu.style.display = 'none';
            }
        });
        
        // ===== PAYMENT METHOD WARNING =====
        document.getElementById('paymentMethod').addEventListener('change', function() {
            const warning = document.getElementById('paymentWarning');
            if (this.value === 'online') {
                warning.style.display = 'block';
            } else {
                warning.style.display = 'none';
            }
        });
        
        // ===== FUNGSI BARU UNTUK ADD TO CART DARI RECOMMENDED ITEMS =====
        function addToCartFromRecommended(itemId, itemName, itemPrice) {
            const btn = event.currentTarget;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="loading-spinner"></span> Adding...';
            btn.disabled = true;
            
            fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: 'add_to_cart',
                    item_id: itemId,
                    name: itemName,
                    price: itemPrice
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    updateCartCount();
                    updateCartTotals();
                    
                    // Animation feedback
                    const cartIcon = document.querySelector('.floating-cart i');
                    if (cartIcon) {
                        cartIcon.style.transform = 'scale(1.3)';
                        setTimeout(() => {
                            cartIcon.style.transform = 'scale(1)';
                        }, 200);
                    }
                } else {
                    showToast(data.message || 'Error adding to cart', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error adding to cart', 'error');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
        
        // ===== UPDATE CART TOTALS =====
        function updateCartTotals() {
            fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: 'get_cart_count'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Also trigger a page reload to update cart items display
                    setTimeout(() => location.reload(), 500);
                }
            });
        }
        
        // ===== CART FUNCTIONS =====
        function updateQuantity(itemId, action) {
            const qtyElement = document.getElementById(`qty-${itemId}`);
            let currentQty = parseInt(qtyElement.textContent);
            let newQty = action === 'increase' ? currentQty + 1 : currentQty - 1;
            
            if (newQty < 1) {
                removeItem(itemId);
                return;
            }
            
            const btn = event.target;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="loading-spinner"></span>';
            btn.disabled = true;
            
            fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: 'update_quantity',
                    item_id: itemId,
                    quantity: newQty
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    qtyElement.textContent = newQty;
                    updateTotals(data.totals);
                    showToast(data.message, 'success');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error updating quantity', 'error');
            })
            .finally(() => {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            });
        }
        
        function updateCustomization(itemId, type, value) {
            const select = event.target;
            select.disabled = true;
            const originalColor = select.style.backgroundColor;
            select.style.backgroundColor = 'rgba(250, 161, 143, 0.3)';
            
            fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: 'update_customization',
                    item_id: itemId,
                    customization_type: type,
                    customization_value: value
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Customization updated', 'success');
                    setTimeout(() => location.reload(), 500);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error updating customization', 'error');
            })
            .finally(() => {
                select.disabled = false;
                select.style.backgroundColor = originalColor;
            });
        }
        
        function removeItem(itemId) {
            if (!confirm('Remove this item from cart?')) return;
            
            const itemElement = document.querySelector(`.cart-item[data-item-id="${itemId}"]`);
            if (itemElement) {
                itemElement.style.opacity = '0.5';
            }
            
            fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: 'remove_item',
                    item_id: itemId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (itemElement) {
                        itemElement.remove();
                    }
                    updateTotals(data.totals);
                    document.getElementById('cartCount').textContent = data.totals.item_count;
                    showToast(data.message, 'success');
                    
                    if (data.totals.item_count === 0) {
                        setTimeout(() => location.reload(), 500);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error removing item', 'error');
                if (itemElement) {
                    itemElement.style.opacity = '1';
                }
            });
        }
        
        function clearCart() {
            if (!confirm('Clear your entire cart?')) return;
            
            fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: 'clear_cart'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Cart cleared', 'success');
                    setTimeout(() => location.reload(), 500);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error clearing cart', 'error');
            });
        }
        
        // ===== ORDER FUNCTIONS =====
        function applyPromo() {
            const promoCode = document.getElementById('promoCode').value;
            const btn = event.target;
            const originalText = btn.innerHTML;
            
            if (!promoCode) {
                showToast('Please enter a promo code', 'error');
                return;
            }
            
            btn.innerHTML = '<span class="loading-spinner"></span> Applying...';
            btn.disabled = true;
            
            fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: 'apply_promo',
                    promo_code: promoCode
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateTotals(data.totals);
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error applying promo', 'error');
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
        
        function updateTotals(totals) {
            document.getElementById('subtotal').textContent = `RM ${totals.subtotal}`;
            document.getElementById('tax').textContent = `RM ${totals.tax}`;
            document.getElementById('deliveryFee').textContent = `RM ${totals.delivery}`;
            document.getElementById('total').textContent = `RM ${totals.total}`;
            document.getElementById('itemCount').textContent = `${totals.item_count} item(s)`;
            document.getElementById('cartCount').textContent = totals.item_count;
            
            // Handle discount row
            const discountRow = document.querySelector('.summary-row:nth-child(4)');
            if (totals.discount && totals.discount !== '0.00') {
                if (!discountRow || discountRow.querySelector('span:first-child').textContent !== 'Discount') {
                    const taxRow = document.querySelector('.summary-row:nth-child(3)');
                    const newRow = document.createElement('div');
                    newRow.className = 'summary-row';
                    newRow.style.color = '#27ae60';
                    newRow.innerHTML = '<span>Discount</span><span id="discount">-RM ' + totals.discount + '</span>';
                    if (taxRow && taxRow.parentNode) {
                        taxRow.parentNode.insertBefore(newRow, taxRow.nextSibling);
                    }
                } else {
                    const discountSpan = document.getElementById('discount');
                    if (discountSpan) {
                        discountSpan.textContent = `-RM ${totals.discount}`;
                    }
                }
            } else if (discountRow && discountRow.querySelector('span:first-child').textContent === 'Discount') {
                discountRow.remove();
            }
        }
        
        // ===== FORM VALIDATION =====
        function validateForm() {
            const name = document.querySelector('input[name="customer_name"]').value.trim();
            let phone = document.querySelector('input[name="customer_phone"]').value.trim();
            const email = document.querySelector('input[name="customer_email"]').value.trim();
            const address = document.querySelector('textarea[name="delivery_address"]').value.trim();
            
            if (!name || !phone || !email || !address) {
                showToast('Please fill in all required fields', 'error');
                return false;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showToast('Please enter a valid email address', 'error');
                return false;
            }
            
            // Phone validation - remove all non-digit characters
            let cleanPhone = phone.replace(/\D/g, '');
            
            // If starts with 60, replace with 0
            if (cleanPhone.startsWith('60')) {
                cleanPhone = '0' + cleanPhone.substring(2);
            }
            
            // Check length (10 or 11 digits)
            if (cleanPhone.length < 10 || cleanPhone.length > 11) {
                showToast('Please enter a valid Malaysian phone number (10-11 digits)', 'error');
                return false;
            }
            
            // Check starts with 01
            if (!cleanPhone.startsWith('01')) {
                showToast('Malaysian phone numbers must start with 01', 'error');
                return false;
            }
            
            // Update input with clean phone number
            document.querySelector('input[name="customer_phone"]').value = cleanPhone;
            
            // Disable button and show loading
            const btn = document.getElementById('placeOrderBtn');
            btn.innerHTML = '<span class="loading-spinner"></span> Processing...';
            btn.disabled = true;
            
            return true;
        }
        
        // ===== TOAST =====
        function showToast(message, type = 'success') {
            // Remove existing toasts
            const existingContainer = document.querySelector('.toast-container');
            if (existingContainer && existingContainer.children.length > 0) {
                const toasts = existingContainer.querySelectorAll('.toast');
                toasts.forEach(toast => {
                    toast.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                });
            }
            
            const container = document.querySelector('.toast-container') || (() => {
                const newContainer = document.createElement('div');
                newContainer.className = 'toast-container';
                document.body.appendChild(newContainer);
                return newContainer;
            })();
            
            const toast = document.createElement('div');
            toast.className = 'toast ' + (type === 'success' ? 'success' : 'error');
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                    if (container.children.length === 0) {
                        container.remove();
                    }
                }, 300);
            }, 3000);
        }
        
        function updateCartCount() {
            fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ajax: true, action: 'get_cart_count' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('cartCount').textContent = data.count;
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // ===== INITIALIZE =====
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide any existing toasts after 5 seconds
            setTimeout(() => {
                const containers = document.querySelectorAll('.toast-container');
                containers.forEach(container => {
                    const toasts = container.querySelectorAll('.toast');
                    toasts.forEach(toast => {
                        toast.style.animation = 'slideOut 0.3s ease';
                        setTimeout(() => {
                            if (toast.parentNode) {
                                toast.remove();
                            }
                            if (container.children.length === 0) {
                                container.remove();
                            }
                        }, 300);
                    });
                });
            }, 5000);
            
            // Add animation on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateX(0)';
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.cart-item').forEach(item => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                item.style.transition = 'all 0.5s ease';
                observer.observe(item);
            });
            
            // Initialize payment warning
            const paymentSelect = document.getElementById('paymentMethod');
            if (paymentSelect && paymentSelect.value === 'online') {
                document.getElementById('paymentWarning').style.display = 'block';
            }
        });
    </script>
</body>
</html>
