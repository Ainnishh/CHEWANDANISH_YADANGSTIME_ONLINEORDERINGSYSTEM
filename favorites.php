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

// User data dari session
$user_id = $_SESSION['user_id'] ?? 0;
$nickname = htmlspecialchars($_SESSION['nickname'] ?? 'Guest');
$phone = htmlspecialchars($_SESSION['phone'] ?? 'Not logged in');

// Default image path
$default_image = 'images/default-drink.jpg';
$upload_dir = 'uploads/menu_items/';

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

// Update session expiry
$_SESSION['session_expire'] = time() + 3600;

$message = '';
$message_type = '';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $item_id = $_POST['item_id'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($action) {
            case 'add_favorite':
                // Check if already exists
                $stmt = $pdo->prepare("SELECT id FROM favorites WHERE user_id = ? AND item_id = ?");
                $stmt->execute([$user_id, $item_id]);
                
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO favorites (user_id, item_id, notes) VALUES (?, ?, ?)");
                    $stmt->execute([$user_id, $item_id, $notes]);
                    $response = ['success' => true, 'message' => 'Added to favorites!'];
                } else {
                    $response = ['success' => false, 'message' => 'Already in favorites'];
                }
                break;
                
            case 'remove_favorite':
                $stmt = $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND item_id = ?");
                $stmt->execute([$user_id, $item_id]);
                $response = ['success' => true, 'message' => 'Removed from favorites'];
                break;
                
            case 'update_notes':
                $stmt = $pdo->prepare("UPDATE favorites SET notes = ? WHERE user_id = ? AND item_id = ?");
                $stmt->execute([$notes, $user_id, $item_id]);
                $response = ['success' => true, 'message' => 'Notes updated'];
                break;
                
            case 'reorder':
                // Get item details
                $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch();
                
                if ($item) {
                    // Initialize cart if not exists
                    if (!isset($_SESSION['cart'])) {
                        $_SESSION['cart'] = [];
                    }
                    
                    // Add to cart - SIMPAN IMAGE URL
                    if (isset($_SESSION['cart'][$item_id])) {
                        $_SESSION['cart'][$item_id]['quantity']++;
                    } else {
                        $_SESSION['cart'][$item_id] = [
                            'name' => $item['name'],
                            'price' => $item['price'],
                            'quantity' => 1,
                            'image' => $item['image_url'] ?? '',
                            'customizations' => [
                                'sweetness' => '100%',
                                'milk' => 'regular',
                                'syrup' => 'none'
                            ]
                        ];
                    }
                    
                    // Update frequency
                    $stmt = $pdo->prepare("UPDATE favorites SET frequency = frequency + 1, last_ordered = NOW() WHERE user_id = ? AND item_id = ?");
                    $stmt->execute([$user_id, $item_id]);
                    
                    $response = ['success' => true, 'message' => 'Added to cart!'];
                }
                break;
                
            case 'reorder_all':
                $favorite_ids = $_POST['favorite_ids'] ?? [];
                if (!empty($favorite_ids) && is_array($favorite_ids)) {
                    // Create placeholders for IN clause
                    $placeholders = implode(',', array_fill(0, count($favorite_ids), '?'));
                    
                    // Get all items
                    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id IN ($placeholders)");
                    $stmt->execute($favorite_ids);
                    $items = $stmt->fetchAll();
                    
                    // Initialize cart if not exists
                    if (!isset($_SESSION['cart'])) {
                        $_SESSION['cart'] = [];
                    }
                    
                    // Add all to cart
                    foreach ($items as $item) {
                        if (isset($_SESSION['cart'][$item['id']])) {
                            $_SESSION['cart'][$item['id']]['quantity']++;
                        } else {
                            $_SESSION['cart'][$item['id']] = [
                                'name' => $item['name'],
                                'price' => $item['price'],
                                'quantity' => 1,
                                'image' => $item['image_url'] ?? '',
                                'customizations' => [
                                    'sweetness' => '100%',
                                    'milk' => 'regular',
                                    'syrup' => 'none'
                                ]
                            ];
                        }
                    }
                    
                    // Update frequency for all
                    $stmt = $pdo->prepare("UPDATE favorites SET frequency = frequency + 1, last_ordered = NOW() WHERE user_id = ? AND item_id IN ($placeholders)");
                    $params = array_merge([$user_id], $favorite_ids);
                    $stmt->execute($params);
                    
                    $response = ['success' => true, 'message' => 'All items added to cart!'];
                }
                break;
                
            case 'get_cart_count':
                $count = 0;
                if (isset($_SESSION['cart'])) {
                    foreach ($_SESSION['cart'] as $item) {
                        $count += $item['quantity'];
                    }
                }
                $response = ['success' => true, 'count' => $count];
                break;
        }
    } catch (PDOException $e) {
        $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit();
}

// Get user's favorites with item details - MariaDB compatible
$stmt = $pdo->prepare("
    SELECT 
        f.*,
        m.name as item_name,
        m.price,
        m.description,
        m.image_url,
        m.category,
        m.is_available,
        m.calories,
        m.prep_time,
        DATEDIFF(NOW(), f.last_ordered) as days_since_last
    FROM favorites f
    JOIN menu_items m ON f.item_id = m.id
    WHERE f.user_id = ?
    ORDER BY 
        -- Unavailable items last
        CASE WHEN m.is_available = 0 THEN 1 ELSE 0 END,
        -- Most frequent first
        f.frequency DESC,
        -- Recently ordered first (NULL dates will be last automatically)
        f.last_ordered DESC,
        -- Newest favorites first
        f.created_at DESC
");
$stmt->execute([$user_id]);
$favorites = $stmt->fetchAll();

// Get favorite stats
$stats = [
    'total' => count($favorites),
    'most_ordered' => 0,
    'total_orders' => 0,
    'categories' => []
];

foreach ($favorites as $fav) {
    $stats['most_ordered'] = max($stats['most_ordered'], $fav['frequency']);
    $stats['total_orders'] += $fav['frequency'];
    $stats['categories'][$fav['category']] = ($stats['categories'][$fav['category']] ?? 0) + 1;
}

// Get recommendations based on favorites
$recommendations = [];
if (!empty($favorites)) {
    $favorite_categories = array_keys($stats['categories']);
    if (!empty($favorite_categories)) {
        $placeholders = implode(',', array_fill(0, count($favorite_categories), '?'));
        
        $stmt = $pdo->prepare("
            SELECT * FROM menu_items 
            WHERE category IN ($placeholders) 
            AND is_available = 1
            AND id NOT IN (SELECT item_id FROM favorites WHERE user_id = ?)
            ORDER BY RAND()
            LIMIT 4
        ");
        $params = array_merge($favorite_categories, [$user_id]);
        $stmt->execute($params);
        $recommendations = $stmt->fetchAll();
    }
}

// Get cart count
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += $item['quantity'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorites | Yadang's Time</title>
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
        
        /* ===== MAIN CONTAINER ===== */
        .favorites-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .favorites-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-title h1 {
            font-size: 2rem;
            color: var(--text-light);
        }
        
        .header-title i {
            color: var(--heart-color);
            font-size: 2rem;
            animation: heartbeat 1.5s ease infinite;
        }
        
        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            25% { transform: scale(1.1); }
            50% { transform: scale(1); }
        }
        
        .header-stats {
            display: flex;
            gap: 1rem;
        }
        
        .stat-badge {
            background: var(--container-bg);
            padding: 0.8rem 1.5rem;
            border-radius: 30px;
            border: 1px solid rgba(250, 161, 143, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* ===== TOAST NOTIFICATION ===== */
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
            animation: slideInRight 0.3s ease;
            box-shadow: 0 5px 20px rgba(250, 161, 143, 0.4);
        }
        
        .toast.error {
            background: #e74c3c;
            color: white;
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
        
        /* ===== FAVORITES GRID ===== */
        .favorites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        .favorite-card {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.4s ease;
            position: relative;
            box-shadow: 0 10px 30px var(--shadow-color);
            animation: fadeIn 0.5s ease-out;
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
        
        .favorite-card:hover {
            transform: translateY(-10px);
            border-color: var(--heart-color);
            box-shadow: 0 15px 30px rgba(255, 107, 107, 0.2);
        }
        
        .favorite-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--heart-color), var(--accent-color));
        }
        
        .card-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            transition: transform 0.8s ease;
        }
        
        .favorite-card:hover .card-image {
            transform: scale(1.05);
        }
        
        .card-content {
            padding: 1.5rem;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .item-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-light);
        }
        
        .item-price {
            background: rgba(255, 107, 107, 0.2);
            color: var(--heart-color);
            padding: 0.4rem 0.8rem;
            border-radius: 15px;
            font-weight: bold;
        }
        
        .item-description {
            color: rgba(232, 223, 208, 0.8);
            font-size: 0.95rem;
            margin-bottom: 1rem;
        }
        
        .favorite-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 0.8rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 10px;
            flex-wrap: wrap;
        }
        
        .stat {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }
        
        .stat i {
            color: var(--accent-color);
        }
        
        .stat.frequency {
            color: var(--heart-color);
            font-weight: bold;
        }
        
        .notes-section {
            background: rgba(26, 44, 34, 0.5);
            padding: 0.8rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .notes-section:hover {
            background: rgba(250, 161, 143, 0.1);
        }
        
        .notes-header {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--accent-color);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }
        
        .notes-content {
            color: rgba(232, 223, 208, 0.9);
            font-size: 0.95rem;
            font-style: italic;
        }
        
        .notes-empty {
            color: rgba(232, 223, 208, 0.5);
        }
        
        .notes-edit {
            display: none;
        }
        
        .notes-edit textarea {
            width: 100%;
            padding: 0.5rem;
            background: var(--dark-bg);
            border: 1px solid var(--accent-color);
            border-radius: 5px;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            font-family: inherit;
        }
        
        .notes-edit textarea:focus {
            outline: none;
            border-color: var(--heart-color);
        }
        
        .notes-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .notes-save {
            background: var(--accent-color);
            color: var(--dark-text);
            border: none;
            padding: 0.3rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .notes-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(250,161,143,0.3);
        }
        
        .notes-cancel {
            background: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 0.3rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .notes-cancel:hover {
            background: var(--accent-color);
            color: var(--dark-text);
        }
        
        .card-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .btn-reorder {
            flex: 2;
            background: linear-gradient(135deg, var(--heart-color), #ff8e8e);
            color: white;
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
        
        .btn-reorder:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }
        
        .btn-reorder:active {
            transform: scale(0.95);
        }
        
        .btn-remove {
            flex: 1;
            background: transparent;
            border: 2px solid var(--heart-color);
            color: var(--heart-color);
            padding: 0.8rem;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-remove:hover {
            background: var(--heart-color);
            color: white;
        }
        
        .unavailable-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0, 0, 0, 0.7);
            color: #e74c3c;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            border: 1px solid #e74c3c;
            z-index: 2;
        }
        
        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--container-bg);
            border-radius: 20px;
            border: 2px dashed var(--heart-color);
        }
        
        .empty-icon {
            font-size: 5rem;
            color: rgba(255, 107, 107, 0.3);
            margin-bottom: 1.5rem;
            animation: float 3s ease infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .browse-menu-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, var(--heart-color), var(--accent-color));
            color: var(--dark-text);
            padding: 1rem 2rem;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 1rem;
            transition: all 0.3s ease;
        }
        
        .browse-menu-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(255, 107, 107, 0.3);
        }
        
        /* ===== BULK ACTIONS ===== */
        .bulk-actions {
            background: var(--container-bg);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .bulk-checkbox {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .bulk-checkbox input {
            width: 18px;
            height: 18px;
            accent-color: var(--heart-color);
            cursor: pointer;
        }
        
        .bulk-btn {
            background: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .bulk-btn:hover {
            background: var(--accent-color);
            color: var(--dark-text);
        }
        
        /* ===== STATS SECTION ===== */
        .stats-section {
            background: var(--container-bg);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 3rem;
        }
        
        .stats-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        
        .stats-card {
            background: rgba(26, 44, 34, 0.5);
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--heart-color);
            margin-bottom: 0.5rem;
        }
        
        /* ===== RECOMMENDATIONS ===== */
        .recommendations-section {
            margin-top: 3rem;
        }
        
        .section-title {
            font-size: 1.8rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .recommendations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .recommend-card {
            background: var(--gradient-light);
            border-radius: 15px;
            overflow: hidden;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.3s ease;
        }
        
        .recommend-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
        }
        
        .recommend-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        
        .recommend-content {
            padding: 1rem;
            text-align: center;
        }
        
        .recommend-name {
            font-weight: 600;
            margin-bottom: 0.3rem;
        }
        
        .recommend-price {
            color: var(--accent-color);
            font-weight: bold;
            margin-bottom: 1rem;
        }
        
        .btn-add-recommend {
            width: 100%;
            background: transparent;
            border: 2px solid var(--accent-color);
            color: var(--accent-color);
            padding: 0.5rem;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-add-recommend:hover {
            background: var(--accent-color);
            color: var(--dark-text);
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
            
            .favorites-header {
                flex-direction: column;
            }
            
            .header-stats {
                width: 100%;
                justify-content: space-around;
            }
            
            .favorites-grid {
                grid-template-columns: 1fr;
            }
            
            .bulk-actions {
                flex-direction: column;
            }
            
            .recommendations-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            #userMenu {
                right: 1rem;
                left: 1rem;
                width: auto;
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
            
            .favorites-grid {
                grid-template-columns: 1fr;
            }
            
            .recommendations-grid {
                grid-template-columns: 1fr;
            }
            
            .card-actions {
                flex-direction: column;
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
            <a href="profile.php"><i class="fas fa-user-cog"></i> Profile Settings</a>
            <a href="order_history.php"><i class="fas fa-history"></i> Order History</a>
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
            <a href="cart.php" class="nav-item">
                <i class="fas fa-shopping-cart"></i> <span>Cart</span>
                <span class="cart-count" id="cartCount"><?php echo $cart_count; ?></span>
            </a>
            <a href="favorites.php" class="nav-item active">
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
    
    <main class="favorites-container" id="main-content">
        <!-- Header -->
        <div class="favorites-header">
            <div class="header-title">
                <i class="fas fa-heart"></i>
                <h1>My Favorites</h1>
            </div>
            
            <div class="header-stats">
                <div class="stat-badge">
                    <i class="fas fa-heart"></i>
                    <span><?php echo $stats['total']; ?> items</span>
                </div>
                <div class="stat-badge">
                    <i class="fas fa-shopping-cart"></i>
                    <span><?php echo $stats['total_orders']; ?> orders</span>
                </div>
            </div>
        </div>
        
        <?php if (empty($favorites)): ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-heart-broken"></i>
                </div>
                <h2>No favorites yet</h2>
                <p>Browse our menu and add items you love!</p>
                <a href="menu.php" class="browse-menu-btn">
                    <i class="fas fa-utensils"></i> Browse Menu
                </a>
            </div>
        <?php else: ?>
            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <div class="bulk-checkbox">
                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                    <label for="selectAll">Select All</label>
                </div>
                <button class="bulk-btn" onclick="removeSelected()">
                    <i class="fas fa-trash"></i> Remove Selected
                </button>
            </div>
            
            <!-- Favorites Grid -->
            <div class="favorites-grid" id="favoritesGrid">
                <?php foreach ($favorites as $fav): ?>
                    <div class="favorite-card" data-item-id="<?php echo $fav['item_id']; ?>">
                        <?php if (!$fav['is_available']): ?>
                            <div class="unavailable-badge">
                                <i class="fas fa-clock"></i> Unavailable
                            </div>
                        <?php endif; ?>
                        
                        <input type="checkbox" class="favorite-checkbox" 
                               data-item-id="<?php echo $fav['item_id']; ?>" 
                               style="position: absolute; top: 15px; left: 15px; z-index: 2; width: 20px; height: 20px; accent-color: var(--heart-color);">
                        
                        <?php 
                        // FIXED: Use correct path for images
                        $image_src = $default_image;
                        if (!empty($fav['image_url'])) {
                            $full_path = $upload_dir . $fav['image_url'];
                            if (file_exists($full_path)) {
                                $image_src = $full_path . '?t=' . filemtime($full_path);
                            }
                        }
                        ?>
                        <img src="<?php echo $image_src; ?>" 
                             alt="<?php echo htmlspecialchars($fav['item_name']); ?>" 
                             class="card-image"
                             onerror="this.src='<?php echo $default_image; ?>'">
                        
                        <div class="card-content">
                            <div class="card-header">
                                <h3 class="item-name"><?php echo htmlspecialchars($fav['item_name']); ?></h3>
                                <div class="item-price">RM <?php echo number_format($fav['price'], 2); ?></div>
                            </div>
                            
                            <p class="item-description"><?php echo htmlspecialchars($fav['description'] ?? ''); ?></p>
                            
                            <div class="favorite-stats">
                                <span class="stat frequency">
                                    <i class="fas fa-heart"></i> Ordered <?php echo $fav['frequency']; ?>x
                                </span>
                                <?php if ($fav['last_ordered']): ?>
                                    <span class="stat">
                                        <i class="fas fa-clock"></i> 
                                        <?php 
                                        $days = $fav['days_since_last'];
                                        if ($days == 0) echo 'Today';
                                        elseif ($days == 1) echo 'Yesterday';
                                        else echo $days . ' days ago';
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="notes-section" onclick="editNotes(<?php echo $fav['item_id']; ?>)">
                                <div class="notes-header">
                                    <i class="fas fa-pen"></i>
                                    <span>Personal Note</span>
                                </div>
                                <div class="notes-content" id="notes-display-<?php echo $fav['item_id']; ?>">
                                    <?php if ($fav['notes']): ?>
                                        "<?php echo htmlspecialchars($fav['notes']); ?>"
                                    <?php else: ?>
                                        <span class="notes-empty">Click to add a note...</span>
                                    <?php endif; ?>
                                </div>
                                <div class="notes-edit" id="notes-edit-<?php echo $fav['item_id']; ?>">
                                    <textarea id="notes-text-<?php echo $fav['item_id']; ?>" rows="2"><?php echo htmlspecialchars($fav['notes'] ?? ''); ?></textarea>
                                    <div class="notes-actions">
                                        <button class="notes-save" onclick="saveNotes(<?php echo $fav['item_id']; ?>, event)">Save</button>
                                        <button class="notes-cancel" onclick="cancelNotes(<?php echo $fav['item_id']; ?>, event)">Cancel</button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card-actions">
                                <button class="btn-reorder" onclick="reorderItem(<?php echo $fav['item_id']; ?>)">
                                    <i class="fas fa-bolt"></i> One-Click Reorder
                                </button>
                                <button class="btn-remove" onclick="removeFavorite(<?php echo $fav['item_id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Stats Section -->
            <div class="stats-section">
                <h2 class="stats-title">
                    <i class="fas fa-chart-pie"></i> Your Stats
                </h2>
                <div class="stats-grid">
                    <div class="stats-card">
                        <div class="stats-number"><?php echo $stats['total']; ?></div>
                        <div class="stats-label">Total Favorites</div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-number"><?php echo $stats['total_orders']; ?></div>
                        <div class="stats-label">Total Orders</div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-number"><?php echo $stats['most_ordered']; ?></div>
                        <div class="stats-label">Most Ordered</div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-number"><?php echo count($stats['categories']); ?></div>
                        <div class="stats-label">Categories</div>
                    </div>
                </div>
            </div>
            
            <!-- Recommendations -->
            <?php if (!empty($recommendations)): ?>
                <div class="recommendations-section">
                    <h2 class="section-title">
                        <i class="fas fa-lightbulb"></i> You Might Also Like
                    </h2>
                    <div class="recommendations-grid">
                        <?php foreach ($recommendations as $item): ?>
                            <div class="recommend-card">
                                <?php 
                                $rec_image_src = $default_image;
                                if (!empty($item['image_url'])) {
                                    $rec_full_path = $upload_dir . $item['image_url'];
                                    if (file_exists($rec_full_path)) {
                                        $rec_image_src = $rec_full_path . '?t=' . filemtime($rec_full_path);
                                    }
                                }
                                ?>
                                <img src="<?php echo $rec_image_src; ?>" 
                                     alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                     class="recommend-image"
                                     onerror="this.src='<?php echo $default_image; ?>'">
                                <div class="recommend-content">
                                    <div class="recommend-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div class="recommend-price">RM <?php echo number_format($item['price'], 2); ?></div>
                                    <button class="btn-add-recommend" onclick="addToFavorites(<?php echo $item['id']; ?>)">
                                        <i class="fas fa-heart"></i> Add to Favorites
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time. All rights reserved.</p>
            <p style="margin-top: 0.5rem;">Made with ෆ for beverage lovers</p>
        </div>
    </footer>
    
    <!-- Toast Notification -->
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
        
        // ===== UPDATE CART COUNT =====
        function updateCartCount() {
            fetch('favorites.php', {
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
        
        // ===== FAVORITE FUNCTIONS =====
        function reorderItem(itemId) {
            const btn = event.currentTarget;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="loading-spinner"></span>';
            btn.disabled = true;
            
            fetch('favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ajax: true, action: 'reorder', item_id: itemId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message);
                    updateCartCount();
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
        
        function removeFavorite(itemId) {
            if (!confirm('Remove from favorites?')) return;
            
            const card = document.querySelector(`.favorite-card[data-item-id="${itemId}"]`);
            card.style.opacity = '0.5';
            
            fetch('favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ajax: true, action: 'remove_favorite', item_id: itemId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    card.remove();
                    showToast(data.message);
                    if (document.querySelectorAll('.favorite-card').length === 0) {
                        setTimeout(() => location.reload(), 500);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error removing item', 'error');
                card.style.opacity = '1';
            });
        }
        
        function editNotes(itemId) {
            document.getElementById(`notes-display-${itemId}`).style.display = 'none';
            document.getElementById(`notes-edit-${itemId}`).style.display = 'block';
        }
        
        function saveNotes(itemId, event) {
            event.stopPropagation();
            const notes = document.getElementById(`notes-text-${itemId}`).value;
            
            fetch('favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ajax: true, action: 'update_notes', item_id: itemId, notes: notes })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById(`notes-display-${itemId}`).innerHTML = notes ? `"${notes}"` : '<span class="notes-empty">Click to add a note...</span>';
                    document.getElementById(`notes-edit-${itemId}`).style.display = 'none';
                    document.getElementById(`notes-display-${itemId}`).style.display = 'block';
                    showToast('Note saved');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error saving note', 'error');
            });
        }
        
        function cancelNotes(itemId, event) {
            event.stopPropagation();
            document.getElementById(`notes-edit-${itemId}`).style.display = 'none';
            document.getElementById(`notes-display-${itemId}`).style.display = 'block';
        }
        
        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('.favorite-checkbox');
            const selectAll = document.getElementById('selectAll');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        }
        
        function reorderSelected() {
            const selected = Array.from(document.querySelectorAll('.favorite-checkbox:checked'))
                .map(cb => cb.dataset.itemId);
            
            if (selected.length === 0) {
                showToast('Please select items first', 'error');
                return;
            }
            
            fetch('favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ 
                    ajax: true, 
                    action: 'reorder_all', 
                    favorite_ids: selected 
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`${selected.length} items added to cart!`);
                    updateCartCount();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error adding to cart', 'error');
            });
        }
        
        function removeSelected() {
            const selected = Array.from(document.querySelectorAll('.favorite-checkbox:checked'))
                .map(cb => cb.dataset.itemId);
            
            if (selected.length === 0) {
                showToast('Please select items first', 'error');
                return;
            }
            
            if (!confirm(`Remove ${selected.length} items from favorites?`)) return;
            
            selected.forEach(itemId => {
                const card = document.querySelector(`.favorite-card[data-item-id="${itemId}"]`);
                if (card) card.remove();
                
                fetch('favorites.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ ajax: true, action: 'remove_favorite', item_id: itemId })
                });
            });
            
            showToast(`${selected.length} items removed`);
            if (document.querySelectorAll('.favorite-card').length === 0) {
                setTimeout(() => location.reload(), 500);
            }
        }
        
        function addToFavorites(itemId) {
            fetch('favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ajax: true, action: 'add_favorite', item_id: itemId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Added to favorites!');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error adding to favorites', 'error');
            });
        }
        
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
                    toast.style.animation = 'slideInRight 0.3s ease';
                }, 300);
            }, 3000);
        }
        
        // ===== INITIALIZE =====
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation on scroll
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
            
            document.querySelectorAll('.favorite-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'all 0.5s ease';
                observer.observe(card);
            });
        });
    </script>
</body>
</html>
