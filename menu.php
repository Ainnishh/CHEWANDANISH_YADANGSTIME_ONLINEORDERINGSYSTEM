<?php
session_start();
require_once 'includes/db.php';

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
        return null;
    }
}

// User data
$user_id = $_SESSION['user_id'] ?? 0;
$nickname = htmlspecialchars($_SESSION['nickname'] ?? 'Guest');
$phone = htmlspecialchars($_SESSION['phone'] ?? 'Not logged in');

// Fetch profile picture
$profile_pic = null;
$db = connectDB();
if ($db) {
    $stmt = $db->prepare("SELECT profile_picture FROM user_profiles WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['profile_picture'])) {
        $profile_path = 'uploads/profile_pics/' . $result['profile_picture'];
        if (file_exists($profile_path)) {
            $profile_pic = $profile_path . '?t=' . filemtime($profile_path);
        }
    }
}

// Get selected category from URL (default to Coffee Classics)
$selected_category = $_GET['category'] ?? 'Coffee Classics';

// Valid categories list
$valid_categories = [
    'Coffee Classics',
    'Matcha Cravings', 
    'Chocolate Series',
    'Tea Selection',
    'Refreshers',
    'Cafe Specials'
];

// Validate selected category
if (!in_array($selected_category, $valid_categories)) {
    $selected_category = 'Coffee Classics';
}

// Fetch all menu items for the selected category (only available ones)
$stmt = $pdo->prepare("SELECT * FROM menu_items WHERE category = ? AND is_available = 1 ORDER BY name ASC");
$stmt->execute([$selected_category]);
$menu_items = $stmt->fetchAll();

// Get user's favorites from database
$favorites = [];
if ($user_id) {
    $stmt = $pdo->prepare("SELECT item_id FROM favorites WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $favorites = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Category details for sidebar
$categories = [
    'Coffee Classics' => ['icon' => '☕', 'color' => '#8B4513', 'description' => 'Rich and aromatic coffee blends'],
    'Matcha Cravings' => ['icon' => '🍵', 'color' => '#90BE6D', 'description' => 'Premium Japanese matcha'],
    'Chocolate Series' => ['icon' => '🍫', 'color' => '#7B3F00', 'description' => 'Decadent chocolate drinks'],
    'Tea Selection' => ['icon' => '🥂', 'color' => '#A67B5B', 'description' => 'Soothing tea varieties'],
    'Refreshers' => ['icon' => '🧃', 'color' => '#FFB347', 'description' => 'Cool and refreshing beverages'],
    'Cafe Specials' => ['icon' => '✨', 'color' => '#9B59B6', 'description' => 'Signature cafe creations']
];

// Get total cart count
$cart_count = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'] ?? 0;
    }
}

// Default image for error handling
$default_image = 'images/default-drink.jpg';
$upload_dir = 'uploads/menu_items/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu | <?php echo $selected_category; ?> | Yadang's Time</title>
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
        
        /* ===== BASE STYLES ===== */
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
        
        /* ===== USER MENU ===== */
        #userMenu {
            display: none;
            position: absolute;
            top: 100%;
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
        
        /* ===== MENU LAYOUT ===== */
        .menu-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .menu-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
        }
        
        @media (max-width: 900px) {
            .menu-layout {
                grid-template-columns: 1fr;
            }
        }
        
        /* ===== SIDEBAR ===== */
        .menu-sidebar {
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
        
        .sidebar-title {
            color: var(--text-light);
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.8rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-title i {
            color: var(--accent-color);
        }
        
        .category-list {
            list-style: none;
        }
        
        .category-item {
            margin-bottom: 0.8rem;
        }
        
        .category-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 1rem 1.5rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 12px;
            color: var(--text-light);
            text-decoration: none;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .category-link:hover {
            background: rgba(250, 161, 143, 0.1);
            border-color: var(--accent-color);
            transform: translateX(5px);
        }
        
        .category-link.active {
            background: var(--accent-color);
            color: var(--dark-text);
            border-color: var(--accent-color);
            font-weight: 600;
        }
        
        .category-icon {
            font-size: 1.8rem;
            width: 35px;
            text-align: center;
        }
        
        .category-desc {
            font-size: 0.85rem;
            color: rgba(232, 223, 208, 0.6);
            margin-top: 0.2rem;
        }
        
        /* ===== MENU CONTENT ===== */
        .menu-content {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 30px var(--shadow-color);
            border: 1px solid rgba(250, 161, 143, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .menu-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .delivery-info {
            background: rgba(26, 44, 34, 0.5);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-left: 5px solid var(--accent-color);
        }
        
        .delivery-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--accent-color);
        }
        
        .delivery-address {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(232, 223, 208, 0.9);
            margin-bottom: 0.5rem;
        }
        
        .delivery-meta {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .served-by {
            color: var(--accent-color);
        }
        
        .asap {
            background: rgba(250, 161, 143, 0.2);
            color: var(--accent-color);
            padding: 0.3rem 1.2rem;
            border-radius: 30px;
            font-weight: 600;
        }
        
        .category-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
        }
        
        .category-header-icon {
            font-size: 2.5rem;
        }
        
        .category-header h2 {
            font-size: 2rem;
            color: var(--text-light);
        }
        
        .item-count {
            margin-left: auto;
            background: rgba(250, 161, 143, 0.2);
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            color: var(--accent-color);
        }
        
        /* ===== MENU ITEMS GRID ===== */
        .menu-items {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .menu-item {
            background: rgba(26, 44, 34, 0.5);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.1);
            transition: all 0.4s ease;
            display: flex;
            flex-direction: column;
            position: relative;
            animation: fadeIn 0.5s ease-out;
        }
        
        .menu-item:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.2);
        }
        
        .item-image {
            width: 100%;
            height: 200px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
            border: 2px solid rgba(250, 161, 143, 0.2);
            position: relative;
        }
        
        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .menu-item:hover .item-image img {
            transform: scale(1.05);
        }
        
        .item-image .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(0deg, rgba(0,0,0,0.4) 0%, transparent 50%);
            pointer-events: none;
        }
        
        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.8rem;
        }
        
        .item-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-light);
        }
        
        .item-price {
            background: rgba(250, 161, 143, 0.2);
            color: var(--accent-color);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .item-desc {
            color: rgba(232, 223, 208, 0.8);
            font-size: 0.95rem;
            margin-bottom: 1rem;
            line-height: 1.5;
            flex-grow: 1;
        }
        
        .item-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: rgba(232, 223, 208, 0.6);
        }
        
        .item-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .item-actions {
            display: flex;
            gap: 0.8rem;
            margin-top: 0.5rem;
        }
        
        .add-btn {
            flex: 2;
            background: linear-gradient(135deg, var(--accent-color), var(--light-accent));
            color: var(--dark-text);
            border: none;
            padding: 0.8rem;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .add-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(250, 161, 143, 0.4);
        }
        
        .add-btn:active {
            transform: scale(0.95);
        }
        
        .favorite-btn {
            flex: 1;
            background: transparent;
            border: 2px solid var(--heart-color);
            color: var(--heart-color);
            padding: 0.8rem;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .favorite-btn:hover {
            background: var(--heart-color);
            color: white;
            transform: scale(1.05);
        }
        
        .favorite-btn.active {
            background: var(--heart-color);
            color: white;
        }
        
        .favorite-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: rgba(26, 44, 34, 0.3);
            border-radius: 15px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: rgba(250, 161, 143, 0.3);
            margin-bottom: 1.5rem;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-light);
        }
        
        .empty-state p {
            color: rgba(232, 223, 208, 0.7);
            margin-bottom: 1.5rem;
        }
        
        .browse-btn {
            display: inline-block;
            background: var(--accent-color);
            color: var(--dark-text);
            text-decoration: none;
            padding: 0.8rem 2rem;
            border-radius: 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .browse-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(250, 161, 143, 0.3);
        }
        
        /* ===== FLOATING CART ===== */
        .floating-cart {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: linear-gradient(135deg, var(--accent-color), var(--light-accent));
            color: var(--dark-text);
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 10px 25px rgba(250, 161, 143, 0.4);
            z-index: 100;
            font-size: 1.3rem;
            transition: all 0.3s ease;
        }
        
        .floating-cart:hover {
            transform: scale(1.1) rotate(10deg);
        }
        
        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4757;
            color: white;
            font-size: 0.8rem;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        /* ===== TOAST ===== */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--accent-color);
            color: var(--dark-text);
            padding: 1rem 1.5rem;
            border-radius: 10px;
            display: none;
            align-items: center;
            gap: 10px;
            z-index: 2000;
            animation: slideIn 0.3s ease;
            box-shadow: 0 5px 20px rgba(250, 161, 143, 0.4);
        }
        
        .toast.error {
            background: #e74c3c;
            color: white;
        }
        
        @keyframes slideIn {
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
            
            .logo-container {
                flex-direction: column;
            }
            
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
            
            .menu-container {
                padding: 0 1rem;
            }
            
            .menu-sidebar {
                position: static;
                padding: 1.5rem;
            }
            
            .category-list {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            .category-item {
                flex: 1 1 auto;
                min-width: 120px;
                margin-bottom: 0;
            }
            
            .category-link {
                padding: 0.8rem;
            }
            
            .category-icon {
                font-size: 1.3rem;
            }
            
            .category-desc {
                display: none;
            }
            
            .menu-items {
                grid-template-columns: 1fr;
            }
            
            .delivery-title {
                font-size: 1.5rem;
            }
            
            .category-header h2 {
                font-size: 1.5rem;
            }
            
            .item-count {
                font-size: 0.8rem;
                padding: 0.2rem 0.8rem;
            }
            
            #userMenu {
                right: 1rem;
                left: 1rem;
                width: auto;
            }
        }
        
        @media (max-width: 480px) {
            .user-avatar {
                width: 40px;
                height: 40px;
            }
            
            .menu-content {
                padding: 1.5rem;
            }
            
            .item-actions {
                flex-direction: column;
            }
            
            .floating-cart {
                bottom: 1rem;
                right: 1rem;
                width: 50px;
                height: 50px;
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
                    <img src="<?php echo htmlspecialchars($profile_pic); ?>" alt="Profile Picture">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            
            <div class="user-details">
                <div class="user-name">Welcome, <?php echo htmlspecialchars($nickname); ?>!</div>
                <div class="user-phone">📱 <?php echo htmlspecialchars($phone); ?></div>
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
            <a href="menu.php" class="nav-item active">
                <i class="fas fa-coffee"></i> <span>Menu</span>
            </a>
            <a href="cart.php" class="nav-item">
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
    
    <!-- Main Container -->
    <div class="menu-container">
        <div class="menu-layout">
            <!-- Sidebar -->
            <aside class="menu-sidebar">
                <h2 class="sidebar-title">
                    <i class="fas fa-list"></i> Categories
                </h2>
                <ul class="category-list">
                    <?php foreach ($categories as $cat_name => $cat_data): ?>
                    <li class="category-item">
                        <a href="?category=<?php echo urlencode($cat_name); ?>" 
                           class="category-link <?php echo $selected_category === $cat_name ? 'active' : ''; ?>">
                            <span class="category-icon"><?php echo $cat_data['icon']; ?></span>
                            <span>
                                <?php echo $cat_name; ?>
                                <?php if ($selected_category === $cat_name): ?>
                                    <div class="category-desc"><?php echo $cat_data['description']; ?></div>
                                <?php endif; ?>
                            </span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </aside>
            
            <!-- Main Content -->
            <main class="menu-content">
                <!-- Delivery Info -->
                <div class="delivery-info">
                    <h1 class="delivery-title">Delivery</h1>
                    <div class="delivery-address">
                        <i class="fas fa-location-dot" style="color: var(--accent-color);"></i>
                        <span>The Sky Residensi, Taman Shamelin Perkasa</span>
                    </div>
                    <div class="delivery-meta">
                        <div class="served-by">
                            <i class="fas fa-store"></i> <strong>Yadang's Time</strong>
                        </div>
                        <div class="asap">
                            <i class="fas fa-clock"></i> ASAP
                        </div>
                    </div>
                </div>
                
                <!-- Category Header -->
                <div class="category-header">
                    <span class="category-header-icon"><?php echo $categories[$selected_category]['icon']; ?></span>
                    <h2><?php echo $selected_category; ?></h2>
                    <span class="item-count"><?php echo count($menu_items); ?> items</span>
                </div>
                
                <!-- Menu Items -->
                <?php if (empty($menu_items)): ?>
                    <div class="empty-state">
                        <i class="fas fa-coffee"></i>
                        <h3>No items available</h3>
                        <p>This category is currently empty. Please check back later!</p>
                        <a href="?category=Coffee%20Classics" class="browse-btn">Browse Coffee Classics</a>
                    </div>
                <?php else: ?>
                    <div class="menu-items">
                        <?php foreach ($menu_items as $item): ?>
                        <div class="menu-item" data-item-id="<?php echo $item['id']; ?>">
                            <div class="item-image">
                                <?php 
                                // Fix: Use correct path to uploaded images
                                $image_path = 'uploads/menu_items/' . $item['image_url'];
                                if (!empty($item['image_url']) && file_exists($image_path)): 
                                ?>
                                    <img src="<?php echo $image_path; ?>?t=<?php echo filemtime($image_path); ?>" 
                                         alt="<?php echo htmlspecialchars($item['name']); ?>"
                                         onerror="this.src='<?php echo $default_image; ?>'">
                                <?php else: ?>
                                    <img src="<?php echo $default_image; ?>" 
                                         alt="<?php echo htmlspecialchars($item['name']); ?>">
                                <?php endif; ?>
                                <div class="image-overlay"></div>
                            </div>
                            
                            <div class="item-header">
                                <h3 class="item-name"><?php echo htmlspecialchars($item['name']); ?></h3>
                                <div class="item-price">RM <?php echo number_format($item['price'], 2); ?></div>
                            </div>
                            
                            <?php if (!empty($item['description'])): ?>
                                <p class="item-desc"><?php echo htmlspecialchars($item['description']); ?></p>
                            <?php endif; ?>
                            
                            <div class="item-meta">
                                <?php if (!empty($item['prep_time'])): ?>
                                    <span><i class="far fa-clock"></i> <?php echo $item['prep_time']; ?> min</span>
                                <?php endif; ?>
                                <?php if (!empty($item['calories'])): ?>
                                    <span><i class="fas fa-fire"></i> <?php echo $item['calories']; ?> cal</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="item-actions">
                                <button class="add-btn" onclick="addToCart(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>', <?php echo $item['price']; ?>)">
                                    <i class="fas fa-cart-plus"></i> Add to Cart
                                </button>
                                <button class="favorite-btn <?php echo in_array($item['id'], $favorites) ? 'active' : ''; ?>" 
                                        onclick="toggleFavorite(<?php echo $item['id']; ?>, this)">
                                    <i class="fas fa-heart"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time. All rights reserved.</p>
            <p style="margin-top: 0.5rem;">Made with ෆ for beverage lovers</p>
        </div>
    </footer>
    
    <!-- Floating Cart -->
    <button class="floating-cart" onclick="window.location.href='cart.php'">
        <i class="fas fa-shopping-cart"></i>
        <span class="cart-badge" id="cartBadge"><?php echo $cart_count; ?></span>
    </button>
    
    <!-- Toast -->
    <div class="toast" id="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toastMessage"></span>
    </div>
    
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
        
        // ===== CART FUNCTIONS =====
        function addToCart(itemId, itemName, itemPrice) {
            const btn = event.target.closest('.add-btn');
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
                    showToast(data.message);
                    updateCartCount();
                    
                    // Animation feedback
                    const cartIcon = document.querySelector('.floating-cart i');
                    cartIcon.style.transform = 'scale(1.3)';
                    setTimeout(() => {
                        cartIcon.style.transform = 'scale(1)';
                    }, 200);
                } else {
                    showToast(data.message, 'error');
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
                    document.getElementById('cartBadge').textContent = data.count;
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // ===== FAVORITES FUNCTIONS - FIXED =====
        function toggleFavorite(itemId, btn) {
            const isActive = btn.classList.contains('active');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="loading-spinner"></span>';
            btn.disabled = true;
            
            const action = isActive ? 'remove_favorite' : 'add_favorite';
            
            fetch('favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: action,
                    item_id: itemId
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    btn.classList.toggle('active');
                    showToast(data.message);
                    
                    // Heart animation
                    const heartIcon = btn.querySelector('i');
                    if (heartIcon) {
                        heartIcon.style.transform = 'scale(1.3)';
                        setTimeout(() => {
                            heartIcon.style.transform = 'scale(1)';
                        }, 200);
                    }
                } else {
                    showToast(data.message || 'Error updating favorite', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error connecting to server', 'error');
            })
            .finally(() => {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            });
        }
        
        // ===== TOAST =====
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            toastMessage.textContent = message;
            toast.className = 'toast ' + (type === 'success' ? '' : 'error');
            toast.style.display = 'flex';
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    toast.style.display = 'none';
                    toast.style.animation = 'slideIn 0.3s ease';
                }, 300);
            }, 3000);
        }
        
        // ===== LAZY LOADING IMAGES =====
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.classList.add('loaded');
                        }
                        imageObserver.unobserve(img);
                    }
                });
            });
            
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
        
        // ===== INITIALIZE =====
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation on scroll for menu items
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.menu-item').forEach(item => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';
                item.style.transition = 'all 0.5s ease';
                observer.observe(item);
            });
            
            // Update cart count on load
            updateCartCount();
        });
        
        // ===== PREVIEW MODAL FOR IMAGES =====
        function previewImage(imageSrc) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.9);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 3000;
                cursor: pointer;
            `;
            
            const img = document.createElement('img');
            img.src = imageSrc;
            img.style.cssText = `
                max-width: 90%;
                max-height: 90%;
                border-radius: 10px;
                border: 3px solid var(--accent-color);
            `;
            
            modal.appendChild(img);
            modal.onclick = () => modal.remove();
            document.body.appendChild(modal);
        }
        
        // Add click event to images for preview
        document.querySelectorAll('.item-image img').forEach(img => {
            img.addEventListener('click', (e) => {
                e.stopPropagation();
                previewImage(img.src);
            });
        });
    </script>
</body>
</html>
