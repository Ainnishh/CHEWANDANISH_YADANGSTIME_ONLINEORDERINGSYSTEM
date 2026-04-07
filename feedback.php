<?php
session_start();
require_once 'includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// User data dari session
$user_id = $_SESSION['user_id'] ?? 0;
$nickname = htmlspecialchars($_SESSION['nickname'] ?? 'Guest');
$phone = htmlspecialchars($_SESSION['phone'] ?? 'Not logged in');
$email = htmlspecialchars($_SESSION['email'] ?? '');

// Fetch profile picture - FIXED with timestamp to avoid cache
$profile_pic = null;
if ($user_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT profile_picture FROM user_profiles WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && !empty($result['profile_picture'])) {
            $profile_path = 'uploads/profile_pics/' . $result['profile_picture'];
            if (file_exists($profile_path)) {
                // Add timestamp to force refresh
                $profile_pic = $profile_path . '?t=' . filemtime($profile_path);
            }
        }
    } catch (PDOException $e) {
        // Profile table might not exist
    }
}

// Update session expiry
$_SESSION['session_expire'] = time() + 3600;

$message = '';
$message_type = '';

// Ensure user exists in users table
if ($user_id > 0) {
    try {
        $check = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $check->execute([$user_id]);
        if (!$check->fetch()) {
            // Insert user if not exists
            $insert = $pdo->prepare("INSERT INTO users (id, phone, nickname, created_at) VALUES (?, ?, ?, NOW())");
            $insert->execute([$user_id, $phone, $nickname]);
        }
    } catch (PDOException $e) {
        // Ignore errors
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];
    
    try {
        if ($action == 'submit_feedback') {
            // Get form data
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $rating = intval($_POST['rating'] ?? 5);
            $category = $_POST['category'] ?? 'general';
            $subject = $_POST['subject'] ?? '';
            $message_text = $_POST['message'] ?? '';
            $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
            $is_public = isset($_POST['is_public']) ? 1 : 0;
            
            // Validate
            if (empty($name) || empty($message_text)) {
                $response['message'] = 'Name and message are required';
                echo json_encode($response);
                exit();
            }
            
            // Ensure user_id is valid
            $user_id = $_SESSION['user_id'] ?? 0;
            
            if ($user_id == 0) {
                $response['message'] = 'Please login again';
                echo json_encode($response);
                exit();
            }
            
            // Insert feedback
            $stmt = $pdo->prepare("
                INSERT INTO feedback (user_id, name, email, phone, rating, category, subject, message, is_anonymous, is_public, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')
            ");
            
            $stmt->execute([
                $user_id,
                $name,
                $email,
                $phone,
                $rating,
                $category,
                $subject,
                $message_text,
                $is_anonymous,
                $is_public
            ]);
            
            // If public and high rating, maybe feature as testimonial
            if ($is_public && $rating >= 4) {
                $feedback_id = $pdo->lastInsertId();
                $stmt = $pdo->prepare("
                    INSERT INTO testimonials (feedback_id, customer_name, rating, comment, is_featured)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $feedback_id,
                    $name,
                    $rating,
                    $message_text,
                    ($rating == 5) ? 1 : 0
                ]);
            }
            
            $response = [
                'success' => true,
                'message' => 'Thank you for your feedback! We appreciate your support. ❤️'
            ];
        }
        
        elseif ($action == 'contact_us') {
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $subject = $_POST['subject'] ?? '';
            $message_text = $_POST['message'] ?? '';
            $is_urgent = isset($_POST['is_urgent']) ? 1 : 0;
            
            if (empty($name) || empty($email) || empty($message_text)) {
                $response['message'] = 'Please fill in all required fields';
                echo json_encode($response);
                exit();
            }
            
            // Get user_id from session
            $user_id = $_SESSION['user_id'] ?? 0;
            
            // Ensure contact_messages has user_id column
            try {
                $pdo->exec("ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER id");
            } catch (PDOException $e) {
                // Column might already exist
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO contact_messages (user_id, name, email, phone, subject, message, is_urgent, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'new')
            ");
            $stmt->execute([$user_id, $name, $email, $phone, $subject, $message_text, $is_urgent]);
            
            $response = [
                'success' => true,
                'message' => 'Message sent! We\'ll get back to you soon.'
            ];
        }
        
        elseif ($action == 'get_faqs') {
            $category = $_POST['category'] ?? '';
            $sql = "SELECT * FROM faqs WHERE is_active = 1";
            $params = [];
            
            if (!empty($category) && $category != 'all') {
                $sql .= " AND category = ?";
                $params[] = $category;
            }
            
            $sql .= " ORDER BY display_order, id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $faqs = $stmt->fetchAll();
            
            $response = ['success' => true, 'faqs' => $faqs];
        }
        
        // Check for new replies
        elseif ($action == 'check_new_replies') {
            $last_check = intval($_POST['last_check'] ?? 0);
            
            if ($user_id > 0 && $last_check > 0) {
                $last_check_date = date('Y-m-d H:i:s', $last_check/1000);
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM feedback 
                    WHERE user_id = ? 
                    AND replied_at > ? 
                    AND admin_reply IS NOT NULL
                ");
                $stmt->execute([$user_id, $last_check_date]);
                $count = $stmt->fetchColumn();
                
                $response = ['success' => true, 'has_new' => $count > 0];
            } else {
                $response = ['success' => true, 'has_new' => false];
            }
        }
        
    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// Get testimonials for display
$testimonials = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM testimonials 
        ORDER BY is_featured DESC, id DESC 
        LIMIT 10
    ");
    $testimonials = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table might not exist yet
}

// Get FAQs
$faqs = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM faqs 
        WHERE is_active = 1 
        ORDER BY display_order, id 
        LIMIT 6
    ");
    $faqs = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table might not exist yet
}

// ===== GET CUSTOMER'S FEEDBACK HISTORY WITH REPLIES =====
$customer_feedback = [];
if ($user_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM feedback 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 20
        ");
        $stmt->execute([$user_id]);
        $customer_feedback = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table might not exist yet
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback | Yadang's Time</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== KEEP YOUR EXISTING CSS HERE (same as before) ===== */
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
            --star-color: #FFD700;
            --heart-color: #ff6b6b;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --info-color: #3498db;
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
        
        /* ===== NEW REPLY NOTIFICATION ===== */
        .reply-notification {
            position: fixed;
            top: 100px;
            right: 20px;
            background: var(--success-color);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(46, 204, 113, 0.4);
            z-index: 2000;
            display: none;
            align-items: center;
            gap: 10px;
            animation: slideInRight 0.3s ease;
            cursor: pointer;
        }
        
        .reply-notification i {
            font-size: 1.3rem;
        }
        
        .reply-notification:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(46, 204, 113, 0.5);
        }
        
        /* ===== MAIN CONTAINER ===== */
        .feedback-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        /* ===== HERO SECTION ===== */
        .feedback-hero {
            text-align: center;
            margin-bottom: 3rem;
            padding: 3rem 2rem;
            background: linear-gradient(135deg, rgba(250, 161, 143, 0.1), rgba(255, 107, 107, 0.1));
            border-radius: 30px;
            border: 2px solid var(--accent-color);
            position: relative;
            overflow: hidden;
        }
        
        .feedback-hero::before {
            content: '❤️';
            position: absolute;
            font-size: 10rem;
            opacity: 0.05;
            right: -2rem;
            top: -2rem;
            transform: rotate(15deg);
        }
        
        .feedback-hero::after {
            content: '☕';
            position: absolute;
            font-size: 8rem;
            opacity: 0.05;
            left: -2rem;
            bottom: -2rem;
            transform: rotate(-15deg);
        }
        
        .hero-title {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--accent-color);
        }
        
        .hero-subtitle {
            font-size: 1.2rem;
            max-width: 700px;
            margin: 0 auto;
            color: rgba(232, 223, 208, 0.9);
        }
        
        /* ===== SECTION TITLES ===== */
        .section-title {
            font-size: 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            padding-bottom: 1rem;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 80px;
            height: 3px;
            background: var(--accent-color);
        }
        
        .section-title i {
            color: var(--accent-color);
        }
        
        /* ===== FEEDBACK GRID ===== */
        .feedback-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        @media (max-width: 992px) {
            .feedback-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* ===== FEEDBACK FORM ===== */
        .feedback-form-card {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 
                0 10px 30px var(--shadow-color),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(250, 161, 143, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .feedback-form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .form-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 2rem;
        }
        
        .form-header i {
            font-size: 2rem;
            color: var(--accent-color);
        }
        
        .form-header h2 {
            font-size: 1.8rem;
        }
        
        /* Rating Stars */
        .rating-container {
            margin-bottom: 2rem;
        }
        
        .rating-label {
            display: block;
            margin-bottom: 0.8rem;
            font-weight: 600;
        }
        
        .stars {
            display: flex;
            gap: 0.5rem;
            font-size: 2rem;
            cursor: pointer;
        }
        
        .stars i {
            color: rgba(255, 215, 0, 0.3);
            transition: all 0.2s ease;
        }
        
        .stars i.active {
            color: var(--star-color);
            text-shadow: 0 0 10px rgba(255, 215, 0, 0.5);
        }
        
        .stars i:hover {
            transform: scale(1.2);
        }
        
        /* Form Groups */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 576px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--accent-color);
            font-weight: 500;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.8rem 1rem;
            background: rgba(26, 44, 34, 0.7);
            border: 2px solid rgba(250, 161, 143, 0.2);
            border-radius: 10px;
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(250, 161, 143, 0.2);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 120px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .checkbox-group input {
            width: 18px;
            height: 18px;
            accent-color: var(--accent-color);
        }
        
        /* Submit Button */
        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--accent-color), var(--heart-color));
            color: var(--dark-text);
            border: none;
            padding: 1rem;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }
        
        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
        }
        
        /* ===== QUICK CONTACT FORM ===== */
        .quick-contact {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2.5rem;
            border: 2px dashed var(--accent-color);
            box-shadow: 0 10px 30px var(--shadow-color);
            position: relative;
            overflow: hidden;
        }
        
        .quick-contact::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .quick-contact h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }
        
        /* ===== FEEDBACK HISTORY SECTION ===== */
        .feedback-history-section {
            margin-top: 3rem;
            margin-bottom: 3rem;
        }
        
        .feedback-history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        
        .feedback-history-card {
            background: var(--gradient-light);
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: fit-content;
        }
        
        .feedback-history-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 15px 25px rgba(250, 161, 143, 0.15);
        }
        
        .feedback-history-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .feedback-rating {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .feedback-date {
            color: rgba(232, 223, 208, 0.6);
            font-size: 0.85rem;
        }
        
        .feedback-subject {
            color: var(--accent-color);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .feedback-message {
            color: var(--text-light);
            line-height: 1.6;
            margin-bottom: 1rem;
            padding-left: 0.8rem;
            border-left: 3px solid var(--accent-color);
            max-height: 150px;
            overflow-y: auto;
        }
        
        .admin-reply-box {
            background: rgba(46, 204, 113, 0.1);
            border-left: 4px solid var(--success-color);
            padding: 1rem;
            margin-top: 1rem;
            border-radius: 8px;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .reply-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }
        
        .reply-header i {
            color: var(--success-color);
        }
        
        .reply-header span {
            font-weight: 600;
            color: var(--success-color);
        }
        
        .reply-date {
            font-size: 0.75rem;
            color: rgba(232, 223, 208, 0.5);
            margin-left: auto;
        }
        
        .reply-content {
            font-style: italic;
            color: rgba(232, 223, 208, 0.9);
            max-height: 100px;
            overflow-y: auto;
        }
        
        .pending-badge {
            background: rgba(241, 196, 15, 0.1);
            border-left: 4px solid var(--warning-color);
            padding: 0.8rem;
            margin-top: 1rem;
            border-radius: 8px;
            color: var(--warning-color);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-replied {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }
        
        .status-pending {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
        }
        
        /* ===== FAQ SECTION ===== */
        .faq-section {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2.5rem;
            margin-bottom: 3rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            box-shadow: 0 10px 30px var(--shadow-color);
            position: relative;
            overflow: hidden;
        }
        
        .faq-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .faq-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .faq-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .faq-item {
            background: rgba(26, 44, 34, 0.5);
            border-radius: 10px;
            padding: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .faq-item:hover {
            background: rgba(26, 44, 34, 0.8);
        }
        
        .faq-question {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            color: var(--accent-color);
        }
        
        .faq-question i {
            transition: transform 0.3s ease;
        }
        
        .faq-item.active .faq-question i {
            transform: rotate(180deg);
        }
        
        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            color: rgba(232, 223, 208, 0.8);
            margin-top: 0.5rem;
            line-height: 1.6;
        }
        
        .faq-item.active .faq-answer {
            max-height: 200px;
        }
        
        /* ===== WARM THANK YOU ===== */
        .thank-you-section {
            text-align: center;
            margin: 4rem 0;
            padding: 3rem 2rem;
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            border: 1px solid rgba(250, 161, 143, 0.2);
            box-shadow: 0 10px 30px var(--shadow-color);
            position: relative;
            overflow: hidden;
        }
        
        .thank-you-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
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
        
        /* ===== TOAST NOTIFICATION ===== */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--accent-color);
            color: var(--dark-text);
            padding: 1rem 1.5rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(250, 161, 143, 0.4);
            z-index: 2000;
            display: none;
            align-items: center;
            gap: 10px;
            animation: slideInRight 0.3s ease;
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
            
            .feedback-container {
                padding: 0 1rem;
            }
            
            .hero-title {
                font-size: 2rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }
            
            .section-title {
                font-size: 1.6rem;
            }
            
            .feedback-history-grid {
                grid-template-columns: 1fr;
            }
            
            .faq-grid {
                grid-template-columns: 1fr;
            }
            
            .feedback-header {
                flex-direction: column;
                align-items: flex-start;
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
            
            .feedback-form-card {
                padding: 1.5rem;
            }
            
            .quick-contact {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Skip to Content Link -->
    <a href="#main-content" class="skip-to-content" style="position: absolute; top: -40px; left: 0; background: var(--accent-color); color: var(--dark-text); padding: 8px 16px; text-decoration: none; font-weight: bold; z-index: 1001;">Skip to main content</a>
    
    <!-- Background Elements -->
    <div class="bg-pattern"></div>
    <div class="bg-leaf leaf-1">
        <i class="fas fa-leaf"></i>
    </div>
    <div class="bg-leaf leaf-2">
        <i class="fas fa-leaf"></i>
    </div>
    
    <!-- New Reply Notification -->
    <div class="reply-notification" id="replyNotification" onclick="scrollToReplies()">
        <i class="fas fa-reply"></i>
        <div>
            <strong>New Reply!</strong>
            <p style="font-size: 0.9rem;">Admin has replied to your feedback</p>
        </div>
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
        <div id="userMenu" style="display: none; width: 100%; margin-top: 1rem; background: var(--card-bg); padding: 1rem; border-radius: 10px; border: 1px solid var(--accent-color);">
            <a href="profile.php" class="nav-item" style="justify-content: center; margin-bottom: 0.5rem;">
                <i class="fas fa-user-cog"></i> Profile Settings
            </a>
            <a href="order_history.php" class="nav-item" style="justify-content: center; margin-bottom: 0.5rem;">
                <i class="fas fa-history"></i> Order History
            </a>
        </div>
    </header>
    
    <!-- Navigation -->
    <nav class="main-nav" aria-label="Main Navigation">
        <div class="nav-container">
            <a href="home.php" class="nav-item">
                <i class="fas fa-home"></i> Home
            </a>
            <a href="menu.php" class="nav-item">
                <i class="fas fa-coffee"></i> Menu
            </a>
            <a href="cart.php" class="nav-item">
                <i class="fas fa-shopping-cart"></i> Cart
                <span class="cart-count" id="cartCount">0</span>
            </a>
            <a href="favorites.php" class="nav-item">
                <i class="fas fa-heart"></i> Favorites
            </a>
            <a href="feedback.php" class="nav-item active">
                <i class="fas fa-comment-dots"></i> Feedback
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>
    
    <main class="feedback-container" id="main-content">
        <!-- Hero Section -->
        <div class="feedback-hero">
            <h1 class="hero-title">We'd Love to Hear From You! ❤️</h1>
            <p class="hero-subtitle">
                Your feedback helps us serve you better. Whether it's a compliment, 
                suggestion, or concern, we're all ears!
            </p>
        </div>
        
        <!-- Feedback Grid -->
        <div class="feedback-grid">
            <!-- Feedback Form -->
            <div class="feedback-form-card">
                <div class="form-header">
                    <i class="fas fa-pen"></i>
                    <h2>Share Your Experience</h2>
                </div>
                
                <form id="feedbackForm">
                    <!-- Rating -->
                    <div class="rating-container">
                        <label class="rating-label">How was your experience? *</label>
                        <div class="stars" id="ratingStars">
                            <i class="far fa-star" data-rating="1"></i>
                            <i class="far fa-star" data-rating="2"></i>
                            <i class="far fa-star" data-rating="3"></i>
                            <i class="far fa-star" data-rating="4"></i>
                            <i class="far fa-star" data-rating="5"></i>
                        </div>
                        <input type="hidden" name="rating" id="ratingValue" value="5">
                    </div>
                    
                    <!-- Name & Email -->
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Your Name *</label>
                            <input type="text" class="form-input" name="name" 
                                   value="<?php echo htmlspecialchars($nickname); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-input" name="email" 
                                   value="<?php echo htmlspecialchars($email); ?>">
                        </div>
                    </div>
                    
                    <!-- Phone & Category -->
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-input" name="phone" 
                                   value="<?php echo htmlspecialchars($phone); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="general">General Feedback</option>
                                <option value="food">Food Quality</option>
                                <option value="service">Service</option>
                                <option value="delivery">Delivery</option>
                                <option value="suggestion">Suggestion</option>
                                <option value="complaint">Complaint</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Subject -->
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" class="form-input" name="subject" 
                               placeholder="Brief summary of your feedback">
                    </div>
                    
                    <!-- Message -->
                    <div class="form-group">
                        <label class="form-label">Your Message *</label>
                        <textarea class="form-textarea" name="message" required 
                                  placeholder="Tell us about your experience..."></textarea>
                    </div>
                    
                    <!-- Options -->
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_anonymous" id="anonymous">
                        <label for="anonymous">Submit anonymously</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_public" id="public" checked>
                        <label for="public">Allow us to share this as a testimonial</label>
                    </div>
                    
                    <button type="submit" class="submit-btn" id="submitFeedback">
                        <i class="fas fa-heart"></i> Send Feedback
                    </button>
                </form>
            </div>
                
            <!-- Quick Contact Form -->
            <div class="quick-contact">
                <h3>
                    <i class="fas fa-bolt" style="color: var(--accent-color);"></i>
                    Quick Message
                </h3>
                
                <form id="quickContactForm">
                    <div class="form-group">
                        <input type="text" class="form-input" name="name" 
                               placeholder="Your Name *" required>
                    </div>
                    
                    <div class="form-group">
                        <input type="email" class="form-input" name="email" 
                               placeholder="Email *" required>
                    </div>
                    
                    <div class="form-group">
                        <input type="text" class="form-input" name="subject" 
                               placeholder="Subject">
                    </div>
                    
                    <div class="form-group">
                        <textarea class="form-textarea" name="message" 
                                  placeholder="Your Message *" rows="3" required></textarea>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_urgent" id="urgent">
                        <label for="urgent">This is urgent</label>
                    </div>
                    
                    <button type="submit" class="submit-btn" style="background: var(--accent-color);">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </form>
            </div>
        </div>
        
        <!-- FEEDBACK HISTORY SECTION - Customer's own feedback with replies -->
        <?php if (!empty($customer_feedback)): ?>
            <div class="feedback-history-section">
                <h2 class="section-title">
                    <i class="fas fa-history"></i>
                    Your Feedback History
                    <?php 
                    $pending_count = 0;
                    foreach ($customer_feedback as $fb) {
                        if (empty($fb['admin_reply']) && ($fb['status'] ?? '') != 'replied') {
                            $pending_count++;
                        }
                    }
                    if ($pending_count > 0): 
                    ?>
                        <span class="status-badge status-pending" style="font-size: 0.9rem;">
                            <?php echo $pending_count; ?> pending
                        </span>
                    <?php endif; ?>
                </h2>
                
                <div class="feedback-history-grid">
                    <?php foreach ($customer_feedback as $fb): ?>
                        <div class="feedback-history-card" id="feedback-<?php echo $fb['id']; ?>">
                            <!-- Feedback Header -->
                            <div class="feedback-header">
                                <div class="feedback-rating">
                                    <span class="rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star" style="color: <?php echo $i <= ($fb['rating'] ?? 0) ? 'var(--star-color)' : 'rgba(255,215,0,0.2)'; ?>"></i>
                                        <?php endfor; ?>
                                    </span>
                                    <span style="color: var(--accent-color);">(<?php echo $fb['rating']; ?>)</span>
                                    
                                    <?php if (!empty($fb['admin_reply'])): ?>
                                        <span class="status-badge status-replied">
                                            <i class="fas fa-check-circle"></i> Replied
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="feedback-date">
                                    <i class="far fa-calendar-alt"></i> <?php echo date('d M Y', strtotime($fb['created_at'])); ?>
                                </div>
                            </div>
                            
                            <!-- Subject (if any) -->
                            <?php if (!empty($fb['subject'])): ?>
                                <div class="feedback-subject">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($fb['subject']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Category -->
                            <div style="margin-bottom: 0.5rem;">
                                <span class="status-badge" style="background: rgba(52,152,219,0.2); color: var(--info-color);">
                                    <i class="fas fa-folder"></i> <?php echo ucfirst($fb['category'] ?? 'General'); ?>
                                </span>
                            </div>
                            
                            <!-- Message -->
                            <div class="feedback-message">
                                <?php echo nl2br(htmlspecialchars($fb['message'])); ?>
                            </div>
                            
                            <!-- Admin Reply (if any) -->
                            <?php if (!empty($fb['admin_reply'])): ?>
                                <div class="admin-reply-box">
                                    <div class="reply-header">
                                        <i class="fas fa-reply"></i>
                                        <span>Admin Response</span>
                                        <?php if (!empty($fb['replied_at'])): ?>
                                            <span class="reply-date">
                                                <i class="far fa-clock"></i> <?php echo date('d M Y', strtotime($fb['replied_at'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="reply-content">
                                        <?php echo nl2br(htmlspecialchars($fb['admin_reply'])); ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="pending-badge">
                                    <i class="fas fa-hourglass-half"></i>
                                    <span>Awaiting admin response. We'll get back to you soon!</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- FAQ Section -->
        <?php if (!empty($faqs)): ?>
            <div class="faq-section">
                <h2 class="section-title">
                    <i class="fas fa-question-circle"></i>
                    Frequently Asked Questions
                </h2>
                
                <div class="faq-grid">
                    <?php foreach ($faqs as $index => $faq): ?>
                        <div class="faq-item" onclick="toggleFAQ(this)">
                            <div class="faq-question">
                                <span><?php echo htmlspecialchars($faq['question']); ?></span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                <?php echo htmlspecialchars($faq['answer']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Warm Thank You Message -->
        <div class="thank-you-section">
            <i class="fas fa-heart" style="font-size: 3rem; color: var(--heart-color); margin-bottom: 1rem;"></i>
            <h2 style="font-size: 2rem; margin-bottom: 1rem;">Thank You for Being Part of Our Story! ☕</h2>
            <p style="font-size: 1.2rem; color: rgba(232, 223, 208, 0.9); max-width: 700px; margin: 0 auto;">
                Every cup of coffee, every smile, every moment shared with you makes Yadang's Time special. 
                Your feedback helps us brew better experiences. Until next time, 
                <strong style="color: var(--accent-color);">more brews, less blues!</strong> ❤️
            </p>
        </div>
    </main>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time. All rights reserved.</p>
            <p style="margin-top: 0.5rem; font-size: 0.8rem;">
                Made with ෆ for beverage lovers everywhere.
            </p>
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
        
        // ===== UPDATE CART COUNT =====
        function updateCartCount() {
            const cart = JSON.parse(localStorage.getItem('yadangs_cart') || '[]');
            const totalItems = cart.reduce((sum, item) => sum + (item.quantity || 1), 0);
            document.getElementById('cartCount').textContent = totalItems;
        }
        setInterval(updateCartCount, 5000);
        
        // ===== RATING STARS =====
        const stars = document.querySelectorAll('#ratingStars i');
        const ratingInput = document.getElementById('ratingValue');
        
        stars.forEach(star => {
            star.addEventListener('mouseover', function() {
                const rating = this.dataset.rating;
                highlightStars(rating);
            });
            star.addEventListener('mouseout', function() {
                const currentRating = ratingInput.value;
                highlightStars(currentRating);
            });
            star.addEventListener('click', function() {
                const rating = this.dataset.rating;
                ratingInput.value = rating;
                highlightStars(rating);
            });
        });
        
        function highlightStars(rating) {
            stars.forEach((star, index) => {
                if (index < rating) {
                    star.className = 'fas fa-star active';
                } else {
                    star.className = 'far fa-star';
                }
            });
        }
        highlightStars(5);
        
        // ===== FEEDBACK FORM =====
        document.getElementById('feedbackForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax', true);
            formData.append('action', 'submit_feedback');
            
            const submitBtn = document.getElementById('submitFeedback');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.disabled = true;
            
            fetch('feedback.php', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message);
                    this.reset();
                    highlightStars(5);
                    ratingInput.value = 5;
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showToast(data.message, 'error');
                }
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // ===== QUICK CONTACT FORM =====
        document.getElementById('quickContactForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax', true);
            formData.append('action', 'contact_us');
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.disabled = true;
            
            fetch('feedback.php', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message);
                    this.reset();
                } else {
                    showToast(data.message, 'error');
                }
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // ===== FAQ TOGGLE =====
        function toggleFAQ(element) {
            element.classList.toggle('active');
        }
        
        // ===== TOAST NOTIFICATION =====
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            toastMessage.textContent = message;
            toast.style.backgroundColor = type === 'success' ? 'var(--accent-color)' : '#e74c3c';
            toast.style.display = 'flex';
            setTimeout(() => toast.style.display = 'none', 3000);
        }
        
        // ===== CHECK FOR NEW REPLIES =====
        function checkForNewReplies() {
            const lastCheck = localStorage.getItem('lastReplyCheck') || Date.now();
            
            fetch('feedback.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: true,
                    action: 'check_new_replies',
                    last_check: lastCheck
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.has_new) {
                    document.getElementById('replyNotification').style.display = 'flex';
                }
                localStorage.setItem('lastReplyCheck', Date.now());
            });
        }
        
        // ===== SCROLL TO REPLIES =====
        function scrollToReplies() {
            const repliesSection = document.querySelector('.feedback-history-section');
            if (repliesSection) {
                repliesSection.scrollIntoView({ behavior: 'smooth' });
                document.getElementById('replyNotification').style.display = 'none';
            }
        }
        
        // ===== INITIALIZE =====
        document.addEventListener('DOMContentLoaded', function() {
            updateCartCount();
            setInterval(checkForNewReplies, 30000);
            
            // Auto-close FAQ items
            const faqItems = document.querySelectorAll('.faq-item');
            faqItems.forEach(item => {
                item.addEventListener('click', function() {
                    faqItems.forEach(other => {
                        if (other !== this && other.classList.contains('active')) {
                            other.classList.remove('active');
                        }
                    });
                });
            });
            
            // Animation on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fade-in');
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.testimonial-card, .contact-card, .faq-item, .feedback-history-card').forEach(card => {
                observer.observe(card);
            });
        });
    </script>
</body>
</html>
