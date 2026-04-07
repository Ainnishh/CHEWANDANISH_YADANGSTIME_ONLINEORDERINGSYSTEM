<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'yadangs_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// User data
$user_id = $_SESSION['user_id'] ?? 0;
$nickname = htmlspecialchars($_SESSION['nickname'] ?? 'Guest');
$phone = htmlspecialchars($_SESSION['phone'] ?? 'Not logged in');

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
        // Ignore error
    }
}

// ===== GET ANNOUNCEMENTS FROM DATABASE =====
$announcements = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM announcements 
        WHERE is_active = 1 
        AND (expiry_date IS NULL OR expiry_date > NOW())
        ORDER BY display_order ASC, created_at DESC
        LIMIT 10
    ");
    $announcements = $stmt->fetchAll();
} catch (PDOException $e) {
    $announcements = [];
}

// ===== GET TESTIMONIALS FROM DATABASE =====
$testimonials = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM testimonials 
        ORDER BY is_featured DESC, id DESC 
        LIMIT 6
    ");
    $testimonials = $stmt->fetchAll();
} catch (PDOException $e) {
    $testimonials = [];
}

// Update session expiry
$_SESSION['session_expire'] = time() + 3600;

// ===== CHECK FOR FIRST LOGIN (USER MANUAL) =====
$show_manual = false;

// Check from URL parameter
if (isset($_GET['first_login']) && $_GET['first_login'] == 1) {
    $show_manual = true;
    // Clear from session so it doesn't show again
    unset($_SESSION['first_login']);
} 
// Check from session
elseif (isset($_SESSION['first_login']) && $_SESSION['first_login'] === true) {
    $show_manual = true;
    unset($_SESSION['first_login']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home | Yadang's Time</title>
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
        
        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2.5rem;
        }
        
        @media (max-width: 1024px) {
            .main-container {
                grid-template-columns: 1fr;
                padding: 0 1.5rem;
            }
        }
        
        .content-card {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 
                0 10px 30px var(--shadow-color),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(250, 161, 143, 0.2);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.4s ease;
        }
        
        .content-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
        }
        
        .content-card::before {
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
            margin-bottom: 1.8rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 80px;
            height: 2px;
            background: var(--accent-color);
        }
        
        .section-title i {
            color: var(--accent-color);
            font-size: 1.8rem;
        }
        
        .about-content {
            font-size: 1.15rem;
            line-height: 1.8;
            color: var(--text-light);
        }
        
        .about-content p {
            margin-bottom: 1.5rem;
            position: relative;
            padding-left: 2rem;
        }
        
        .about-content p::before {
            content: '☕';
            position: absolute;
            left: 0;
            color: var(--accent-color);
            font-size: 1.2rem;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2.5rem;
        }
        
        .feature-item {
            background: rgba(250, 161, 143, 0.1);
            padding: 1.8rem;
            border-radius: 15px;
            text-align: center;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.3s ease;
        }
        
        .feature-item:hover {
            border-color: var(--accent-color);
            background: rgba(250, 161, 143, 0.2);
            transform: translateY(-5px);
        }
        
        .feature-icon {
            font-size: 2.5rem;
            color: var(--accent-color);
            margin-bottom: 1rem;
            display: block;
        }
        
        .feature-title {
            color: var(--text-light);
            font-size: 1.3rem;
            margin-bottom: 0.8rem;
            font-weight: 600;
        }
        
        .feature-desc {
            color: var(--text-light);
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .gallery-item {
            border-radius: 15px;
            overflow: hidden;
            height: 180px;
            position: relative;
            cursor: pointer;
            transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 2px solid transparent;
        }
        
        .gallery-item:hover {
            transform: scale(1.05);
            border-color: var(--accent-color);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
            z-index: 2;
        }
        
        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.8s ease;
        }
        
        .gallery-item:hover img {
            transform: scale(1.1);
        }
        
        .gallery-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(26, 44, 34, 0.9));
            padding: 1rem;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }
        
        .gallery-item:hover .gallery-overlay {
            transform: translateY(0);
        }
        
        .hours-card {
            background: linear-gradient(135deg, rgba(45, 70, 56, 0.9), rgba(61, 92, 73, 0.9));
            padding: 2rem;
            border-radius: 15px;
            border: 2px solid rgba(250, 161, 143, 0.3);
        }
        
        .status-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 1.2rem;
            border-radius: 12px;
            margin-bottom: 1.8rem;
            font-weight: 700;
            font-size: 1.3rem;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
        }
        
        .status-open {
            background: linear-gradient(135deg, rgba(107, 142, 35, 0.2), transparent);
            border: 2px solid var(--accent-color);
            color: var(--accent-color);
        }
        
        .status-closed {
            background: linear-gradient(135deg, rgba(139, 115, 85, 0.2), transparent);
            border: 2px solid #999;
            color: #999;
        }
        
        .hours-list {
            list-style: none;
        }
        
        .hours-list li {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(250, 161, 143, 0.1);
            font-size: 1.1rem;
        }
        
        .hours-list li:last-child {
            border-bottom: none;
        }
        
        .day {
            color: var(--text-light);
            font-weight: 600;
        }
        
        .time {
            color: var(--accent-color);
            font-weight: 600;
            font-family: monospace;
        }
        
        .updates-container {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .update-item {
            background: rgba(250, 161, 143, 0.1);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--accent-color);
            animation: slideIn 0.5s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .update-date {
            color: var(--accent-color);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: monospace;
        }
        
        .update-content {
            color: var(--text-light);
            font-size: 1.05rem;
            line-height: 1.6;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2.5rem;
        }
        
        .action-button {
            background: linear-gradient(135deg, var(--accent-color), var(--light-accent));
            color: var(--dark-text);
            border: none;
            padding: 1.5rem 1rem;
            border-radius: 15px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .action-button:hover {
            transform: translateY(-8px) scale(1.05);
            box-shadow: 0 15px 30px rgba(250, 161, 143, 0.4);
        }
        
        .action-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: 0.5s;
        }
        
        .action-button:hover::before {
            left: 100%;
        }
        
        .action-button i {
            font-size: 2.2rem;
        }
        
        .testimonials-section {
            margin-top: 3rem;
        }
        
        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .testimonial-card {
            background: var(--gradient-light);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.3s ease;
            position: relative;
            box-shadow: 0 10px 30px var(--shadow-color);
        }
        
        .testimonial-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
        }
        
        .testimonial-card.featured::before {
            content: '⭐ FEATURED';
            position: absolute;
            top: -10px;
            right: 20px;
            background: var(--accent-color);
            color: var(--dark-text);
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            z-index: 2;
        }
        
        .testimonial-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .testimonial-avatar {
            width: 50px;
            height: 50px;
            background: var(--accent-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--dark-text);
        }
        
        .testimonial-info {
            flex: 1;
        }
        
        .testimonial-name {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .testimonial-rating {
            color: #FFD700;
            font-size: 0.9rem;
        }
        
        .testimonial-comment {
            color: rgba(232, 223, 208, 0.9);
            line-height: 1.6;
            margin-bottom: 1rem;
            font-style: italic;
        }
        
        .testimonial-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: rgba(232, 223, 208, 0.6);
            font-size: 0.9rem;
        }
        
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
        
        .footer-logo {
            font-size: 2.5rem;
            color: var(--accent-color);
            margin-bottom: 1rem;
            font-weight: 700;
            letter-spacing: 2px;
        }
        
        .footer-tagline {
            font-size: 1.1rem;
            color: rgba(245, 233, 217, 0.8);
            margin-bottom: 2rem;
            font-style: italic;
        }
        
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .contact-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 1.5rem;
            background: rgba(250, 161, 143, 0.1);
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .contact-item:hover {
            background: rgba(250, 161, 143, 0.2);
            transform: translateY(-5px);
        }
        
        .contact-item i {
            color: var(--accent-color);
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .contact-label {
            font-weight: 600;
            color: var(--text-light);
        }
        
        .contact-value {
            color: rgba(245, 233, 217, 0.9);
            font-size: 0.95rem;
        }
        
        .copyright {
            color: rgba(245, 233, 217, 0.6);
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        /* ===== USER MANUAL MODAL STYLES ===== */
        .manual-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(5px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes modalFadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        
        .manual-modal-content {
            background: #2d4638;
            border-radius: 20px;
            max-width: 550px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            border: 3px solid #FAA18F;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .manual-modal-header {
            background: rgba(250, 161, 143, 0.15);
            padding: 1.5rem;
            border-bottom: 2px solid #FAA18F;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: #2d4638;
            z-index: 1;
        }
        
        .manual-modal-header h2 {
            color: #FAA18F;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        
        .manual-close {
            background: transparent;
            border: none;
            color: #E8DFD0;
            font-size: 2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            line-height: 1;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .manual-close:hover {
            background: rgba(250, 161, 143, 0.2);
            color: #FAA18F;
            transform: rotate(90deg);
        }
        
        .manual-modal-body {
            padding: 1.5rem;
        }
        
        .welcome-text {
            color: #E8DFD0;
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .manual-step {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .manual-step:hover {
            background: rgba(250, 161, 143, 0.1);
            transform: translateX(5px);
        }
        
        .step-icon {
            width: 50px;
            height: 50px;
            background: rgba(250, 161, 143, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #FAA18F;
            flex-shrink: 0;
        }
        
        .step-content h3 {
            color: #FAA18F;
            font-size: 1.1rem;
            margin-bottom: 0.3rem;
        }
        
        .step-content p {
            color: #E8DFD0;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .manual-tip {
            background: rgba(46, 204, 113, 0.15);
            border-left: 4px solid #2ecc71;
            padding: 1rem;
            border-radius: 10px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .manual-tip i {
            color: #2ecc71;
            font-size: 1.2rem;
        }
        
        .manual-tip strong {
            color: #2ecc71;
        }
        
        .manual-tip span {
            color: #E8DFD0;
        }
        
        .manual-modal-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(250, 161, 143, 0.3);
            text-align: center;
        }
        
        .btn-gotit {
            background: linear-gradient(135deg, #FAA18F, #ffc2b5);
            color: #0f1913;
            border: none;
            padding: 0.8rem 2rem;
            border-radius: 30px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-gotit:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
        }
        
        .manual-modal-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .manual-modal-content::-webkit-scrollbar-track {
            background: rgba(26, 44, 34, 0.5);
            border-radius: 10px;
        }
        
        .manual-modal-content::-webkit-scrollbar-thumb {
            background: #FAA18F;
            border-radius: 10px;
        }
        
        @media (max-width: 768px) {
            .manual-modal-content {
                width: 95%;
                max-height: 90vh;
            }
            
            .manual-step {
                flex-direction: column;
                text-align: center;
            }
            
            .step-icon {
                margin: 0 auto;
            }
            
            .manual-modal-header h2 {
                font-size: 1.2rem;
            }
        }
        
        @media (max-width: 480px) {
            .manual-step {
                padding: 0.8rem;
            }
            
            .step-content h3 {
                font-size: 1rem;
            }
            
            .step-content p {
                font-size: 0.85rem;
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
            
            .main-container {
                padding: 0 1rem;
                gap: 1.5rem;
            }
            
            .content-card {
                padding: 1.5rem;
            }
            
            .section-title {
                font-size: 1.6rem;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
            }
            
            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .contact-grid {
                grid-template-columns: 1fr;
            }
            
            .testimonials-grid {
                grid-template-columns: 1fr;
            }
            
            .feature-item {
                padding: 1.2rem;
            }
            
            .action-button {
                padding: 1.2rem 1rem;
            }
            
            .update-item {
                padding: 1rem;
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
            
            .content-card {
                padding: 1.2rem;
            }
            
            .section-title {
                font-size: 1.4rem;
                gap: 10px;
            }
            
            .gallery-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .skip-to-content {
            position: absolute;
            top: -40px;
            left: 0;
            background: var(--accent-color);
            color: var(--dark-text);
            padding: 8px 16px;
            text-decoration: none;
            font-weight: bold;
            z-index: 1001;
        }
        
        .skip-to-content:focus {
            top: 0;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in {
            animation: fadeInUp 0.8s ease-out;
        }
        
        ::-webkit-scrollbar {
            width: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(26, 44, 34, 0.5);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 5px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--light-accent);
        }
    </style>
</head>
<body>
    <!-- Skip to Content Link -->
    <a href="#main-content" class="skip-to-content">Skip to main content</a>
    
    <!-- Background Elements -->
    <div class="bg-pattern"></div>
    <div class="bg-leaf leaf-1">
        <i class="fas fa-leaf"></i>
    </div>
    <div class="bg-leaf leaf-2">
        <i class="fas fa-leaf"></i>
    </div>
    
    <!-- USER MANUAL POPUP MODAL - HANYA UNTUK FIRST LOGIN -->
    <?php if ($show_manual): ?>
    <div id="userManualModal" class="manual-modal" style="display: flex;">
        <div class="manual-modal-content">
            <div class="manual-modal-header">
                <h2><i class="fas fa-book-open"></i> Welcome to Yadang's Time!</h2>
                <button class="manual-close" onclick="closeManual()">&times;</button>
            </div>
            <div class="manual-modal-body">
                <p class="welcome-text">Here's a quick guide to help you get started:</p>
                
                <div class="manual-step">
                    <div class="step-icon"><i class="fas fa-coffee"></i></div>
                    <div class="step-content">
                        <h3>1. Browse Our Menu</h3>
                        <p>Explore our delicious drinks by category. Click on any item to see details and customize your drink.</p>
                    </div>
                </div>
                
                <div class="manual-step">
                    <div class="step-icon"><i class="fas fa-cart-plus"></i></div>
                    <div class="step-content">
                        <h3>2. Add to Cart</h3>
                        <p>Click "Add to Cart" button to order your favorite drinks. You can customize sugar level, milk type, and syrup before adding.</p>
                    </div>
                </div>
                
                <div class="manual-step">
                    <div class="step-icon"><i class="fas fa-heart"></i></div>
                    <div class="step-content">
                        <h3>3. Save Favorites</h3>
                        <p>Click the heart icon ❤️ to save your favorite drinks for quick reordering later. Access them anytime from the Favorites page.</p>
                    </div>
                </div>
                
                <div class="manual-step">
                    <div class="step-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="step-content">
                        <h3>4. Checkout</h3>
                        <p>Review your cart, enter delivery details, and choose payment method (Cash on Delivery or Online Payment via FPX/Card).</p>
                    </div>
                </div>
                
                <div class="manual-step">
                    <div class="step-icon"><i class="fas fa-clock"></i></div>
                    <div class="step-content">
                        <h3>5. Track Your Order</h3>
                        <p>View your order history and track the status of your orders anytime from your profile.</p>
                    </div>
                </div>
                
                <div class="manual-step">
                    <div class="step-icon"><i class="fas fa-comment-dots"></i></div>
                    <div class="step-content">
                        <h3>6. Share Feedback</h3>
                        <p>Love our drinks? Let us know! Your feedback helps us improve and serve you better.</p>
                    </div>
                </div>
                
                <div class="manual-tip">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Pro Tip:</strong> Save your favorite drinks to reorder them with just one click! Customize your drink exactly how you like it.
                </div>
            </div>
            <div class="manual-modal-footer">
                <button class="btn-gotit" onclick="closeManual()">Got it! Let's Order <i class="fas fa-arrow-right"></i></button>
            </div>
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
            <a href="home.php" class="nav-item active">
                <i class="fas fa-home"></i> Home
            </a>
            <a href="menu.php" class="nav-item">
                <i class="fas fa-coffee"></i> Menu
            </a>
            <a href="cart.php" class="nav-item">
                <i class="fas fa-shopping-cart"></i> Cart
                <span id="cartCount" style="background: var(--accent-color); color: var(--dark-text); padding: 2px 8px; border-radius: 10px; font-size: 0.8rem;">0</span>
            </a>
            <a href="favorites.php" class="nav-item">
                <i class="fas fa-heart"></i> Favorites
            </a>
            <a href="feedback.php" class="nav-item">
                <i class="fas fa-comment-dots"></i> Feedback
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-container" id="main-content">
        <!-- Left Column -->
        <div class="main-content">
            <!-- About Section -->
            <section class="content-card fade-in">
                <h2 class="section-title">
                    <i class="fas fa-store"></i> Our Story
                </h2>
                <div class="about-content">
                    <p>Welcome to <strong>YADANG'S TIME</strong>, your favorite beverage sanctuary near campus! Founded with a simple mission: to serve quality drinks that brighten your day.</p>
                    
                    <p>Our name "Yadang" comes from the Malay word meaning "leisure time" - exactly what we want you to enjoy with every sip. Whether you need a morning pick-me-up or an afternoon refresher, we've got the perfect brew for you.</p>
                    
                    <p>We source our ingredients locally and prepare each drink with care. From our signature coffee blends to refreshing fruit smoothies, every item on our menu is crafted to bring you "More Brews, Less Blues!"</p>
                </div>
                
                <div class="features-grid">
                    <div class="feature-item">
                        <i class="fas fa-seedling feature-icon"></i>
                        <h3 class="feature-title">Fresh Ingredients</h3>
                        <p class="feature-desc">Locally sourced daily</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-hand-holding-heart feature-icon"></i>
                        <h3 class="feature-title">Made with Love</h3>
                        <p class="feature-desc">Every cup matters</p>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-clock feature-icon"></i>
                        <h3 class="feature-title">Fast Service</h3>
                        <p class="feature-desc">5-minute pickup guarantee</p>
                    </div>
                </div>
            </section>
            
            <!-- Gallery Section -->
            <section class="content-card fade-in">
                <h2 class="section-title">
                    <i class="fas fa-images"></i> Gallery
                </h2>
                <p style="margin-bottom: 1.5rem; color: var(--accent-color);">Take a peek at our cozy stall and delicious creations!</p>
                
                <div class="gallery-grid">
                    <div class="gallery-item">
                        <img src="images/Yadang's Stall.jpeg" 
                             alt="Our cozy beverage stall">
                        <div class="gallery-overlay">
                            <p style="color: white; font-size: 0.9rem;">Our cozy stall</p>
                        </div>
                    </div>
                    <div class="gallery-item">
                        <img src="images/Signature.jpeg" 
                             alt="Signature coffee preparation">
                        <div class="gallery-overlay">
                            <p style="color: white; font-size: 0.9rem;">Signature coffee</p>
                        </div>
                    </div>
                    <div class="gallery-item">
                        <img src="images/Staff.jpeg" 
                             alt="Our friendly barista">
                        <div class="gallery-overlay">
                            <p style="color: white; font-size: 0.9rem;">Friendly barista</p>
                        </div>
                    </div>
                    <div class="gallery-item">
                        <img src="images/Customers.jpeg" 
                             alt="Happy customers enjoying drinks">
                        <div class="gallery-overlay">
                            <p style="color: white; font-size: 0.9rem;">Happy customers</p>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- Testimonials Section -->
            <?php if (!empty($testimonials)): ?>
                <section class="content-card fade-in">
                    <h2 class="section-title">
                        <i class="fas fa-star"></i> What Our Customers Say
                    </h2>
                    
                    <div class="testimonials-grid">
                        <?php foreach ($testimonials as $t): ?>
                            <div class="testimonial-card <?php echo $t['is_featured'] ? 'featured' : ''; ?>">
                                <div class="testimonial-header">
                                    <div class="testimonial-avatar">
                                        <?php echo strtoupper(substr($t['customer_name'], 0, 1)); ?>
                                    </div>
                                    <div class="testimonial-info">
                                        <div class="testimonial-name">
                                            <?php echo htmlspecialchars($t['customer_name']); ?>
                                        </div>
                                        <div class="testimonial-rating">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star" style="color: <?php echo $i <= $t['rating'] ? '#FFD700' : 'rgba(255,215,0,0.2)'; ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <p class="testimonial-comment">
                                    "<?php echo htmlspecialchars($t['comment']); ?>"
                                </p>
                                
                                <div class="testimonial-footer">
                                    <span>
                                        <i class="far fa-clock"></i> 
                                        <?php echo date('d M Y', strtotime($t['approved_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="menu.php" class="action-button">
                    <i class="fas fa-bolt"></i>
                    Quick Order
                </a>
                <a href="favorites.php" class="action-button">
                    <i class="fas fa-redo"></i>
                    Reorder Favorite
                </a>
            </div>
        </div>
        
        <!-- Right Column -->
        <div class="sidebar">
            <!-- Operating Hours -->
            <section class="hours-card content-card">
                <h2 class="section-title">
                    <i class="fas fa-clock"></i> Hours
                </h2>
                <div class="status-indicator" id="hoursStatus">
                </div>
                <ul class="hours-list">
                    <li><span class="day">Monday - Friday</span> <span class="time">12:00 PM - 10:00 PM</span></li>
                    <li><span class="day">Saturday</span> <span class="time">12:00 PM - 10:00 PM</span></li>
                    <li><span class="day">Sunday</span> <span class="time">12:00 PM - 10:00 PM</span></li>
                    <li><span class="day">Public Holidays</span> <span class="time">11:30 AM - 10:00 PM</span></li>
                </ul>
            </section>
            
            <!-- Latest Updates -->
            <section class="content-card">
                <h2 class="section-title">
                    <i class="fas fa-bullhorn"></i> Latest Updates
                </h2>
                <div class="updates-container">
                    <?php if (empty($announcements)): ?>
                        <div class="update-item">
                            <div class="update-content">
                                No announcements at the moment. Check back later!
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($announcements as $a): ?>
                            <div class="update-item">
                                <div class="update-date">
                                    <i class="far fa-calendar"></i> <?php echo date('d M Y', strtotime($a['created_at'])); ?>
                                    <?php if ($a['type'] == 'promo'): ?>
                                        <span style="margin-left: 0.5rem; background: rgba(46,204,113,0.2); color: #2ecc71; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem;">PROMO</span>
                                    <?php elseif ($a['type'] == 'job'): ?>
                                        <span style="margin-left: 0.5rem; background: rgba(52,152,219,0.2); color: #3498db; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem;">JOB</span>
                                    <?php elseif ($a['type'] == 'update'): ?>
                                        <span style="margin-left: 0.5rem; background: rgba(155,89,182,0.2); color: #9b59b6; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem;">UPDATE</span>
                                    <?php endif; ?>
                                </div>
                                <div class="update-content">
                                    <?php if ($a['priority'] == 'urgent'): ?>
                                        <span style="color: #e74c3c;">⚠️</span>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($a['title']); ?></strong><br>
                                    <?php echo nl2br(htmlspecialchars($a['content'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
            
            <!-- Weather Widget -->
            <section class="content-card">
                <h2 class="section-title">
                    <i class="fas fa-cloud-sun"></i> Today's Weather
                </h2>
                <div id="weatherWidget" style="text-align: center; padding: 1.5rem;">
                    <div style="font-size: 3rem; color: var(--accent-color); margin-bottom: 1rem;">
                        <i class="fas fa-sun"></i>
                    </div>
                    <div style="font-size: 2rem; font-weight: bold; color: var(--text-light);">
                        28°C
                    </div>
                    <div style="color: var(--accent-color); margin-top: 0.5rem;">
                        Sunny • Perfect for iced drinks!
                    </div>
                    <button onclick="refreshWeather()" style="margin-top: 1rem; background: rgba(250, 161, 143, 0.2); border: 1px solid var(--accent-color); color: var(--accent-color); padding: 0.5rem 1rem; border-radius: 20px; cursor: pointer;">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </section>
        </div>
    </main>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="footer-logo">YADANG'S TIME</div>
        <div class="footer-tagline">more brews, less blues</div>
        
        <div class="contact-grid">
            <div class="contact-item">
                <i class="fas fa-map-marker-alt"></i>
                <span class="contact-label">Location</span>
                <span class="contact-value">The Sky Residensi, Taman Shamelin Perkasa</span>
                <span class="contact-value">Ayam Gepuk Artisan, Taman Shamelin Perkasa</span>
            </div>
            <div class="contact-item">
                <i class="fas fa-phone"></i>
                <span class="contact-label">Phone</span>
                <span class="contact-value">+60 16-630 7707 (Yadang)</span>
                <span class="contact-value">+60 18-217 4308 (Syu)</span>
                <span class="contact-value">+60 11-2690 5984 (James)</span>
            </div>
            <div class="contact-item">
                <i class="fas fa-envelope"></i>
                <span class="contact-label">Email</span>
                <span class="contact-value">hello@yadangstime.com</span>
            </div>
            <div class="contact-item">
                <i class="fas fa-clock"></i>
                <span class="contact-label">Delivery Hours</span>
                <span class="contact-value">2:00 PM - 8:00 PM Daily</span>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time. All rights reserved.</p>
            <p style="margin-top: 0.5rem; font-size: 0.8rem;">
                Made with ෆ for beverage lovers everywhere.
            </p>
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
        
        // ===== USER MANUAL MODAL FUNCTIONS =====
        function closeManual() {
            const modal = document.getElementById('userManualModal');
            if (modal) {
                modal.style.animation = 'modalFadeOut 0.3s ease';
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            }
            // Save to localStorage that user has seen the manual (optional)
            localStorage.setItem('yadangs_manual_seen', 'true');
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('userManualModal');
            if (modal && modal.style.display === 'flex') {
                const content = modal.querySelector('.manual-modal-content');
                if (content && !content.contains(event.target)) {
                    closeManual();
                }
            }
        });
        
        // ===== OPERATING HOURS LOGIC =====
        function updateHoursStatus() {
            const now = new Date();
            const day = now.getDay(); 
            const hour = now.getHours();
            const minute = now.getMinutes();
            
            const statusElement = document.getElementById('hoursStatus');
            let isOpen = false;
            
            const hours = {
                1: { open: 8, close: 22 },
                2: { open: 8, close: 22 },
                3: { open: 8, close: 22 },
                4: { open: 8, close: 22 },
                5: { open: 8, close: 22 },
                6: { open: 9, close: 23 },
                0: { open: 9, close: 21 }
            };
            
            if (hours[day]) {
                const { open, close } = hours[day];
                const currentTime = hour + (minute / 60);
                isOpen = currentTime >= open && currentTime < close;
            }
            
            if (isOpen) {
                statusElement.className = 'status-indicator status-open';
                statusElement.innerHTML = `
                    <i class="fas fa-door-open"></i>
                    <span>OPEN NOW</span>
                    <div style="font-size: 0.9rem; margin-left: auto;">Until ${hours[day].close}:00</div>
                `;
            } else {
                statusElement.className = 'status-indicator status-closed';
                statusElement.innerHTML = `
                    <i class="fas fa-door-closed"></i>
                    <span>CLOSED</span>
                    <div style="font-size: 0.9rem; margin-left: auto;">Opens tomorrow at ${hours[(day + 1) % 7].open}:00</div>
                `;
            }
        }
        
        // ===== CART COUNT UPDATE =====
        function updateCartCount() {
            const cart = JSON.parse(localStorage.getItem('yadangs_cart') || '[]');
            const cartCount = document.getElementById('cartCount');
            const totalItems = cart.reduce((sum, item) => sum + (item.quantity || 1), 0);
            cartCount.textContent = totalItems;
            cartCount.style.display = totalItems > 0 ? 'inline-block' : 'none';
        }
        
        // ===== WEATHER WIDGET =====
        function refreshWeather() {
            const weatherWidget = document.getElementById('weatherWidget');
            weatherWidget.innerHTML = `
                <div style="font-size: 3rem; color: var(--accent-color); margin-bottom: 1rem;">
                    <i class="fas fa-sync-alt fa-spin"></i>
                </div>
                <div>Loading weather...</div>
            `;
            
            setTimeout(() => {
                const temps = [28, 29, 30, 27, 26];
                const weathers = ['sun', 'cloud-sun', 'cloud', 'cloud-rain', 'bolt'];
                const temp = temps[Math.floor(Math.random() * temps.length)];
                const weather = weathers[Math.floor(Math.random() * weathers.length)];
                const messages = [
                    'Perfect for iced drinks!',
                    'Great for hot coffee',
                    'Nice weather for tea',
                    'Good day for smoothies',
                    'Perfect brewing weather'
                ];
                const message = messages[Math.floor(Math.random() * messages.length)];
                
                weatherWidget.innerHTML = `
                    <div style="font-size: 3rem; color: var(--accent-color); margin-bottom: 1rem;">
                        <i class="fas fa-${weather}"></i>
                    </div>
                    <div style="font-size: 2rem; font-weight: bold; color: var(--text-light);">
                        ${temp}°C
                    </div>
                    <div style="color: var(--accent-color); margin-top: 0.5rem;">
                        ${message}
                    </div>
                    <button onclick="refreshWeather()" style="margin-top: 1rem; background: rgba(250, 161, 143, 0.2); border: 1px solid var(--accent-color); color: var(--accent-color); padding: 0.5rem 1rem; border-radius: 20px; cursor: pointer;">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                `;
            }, 1000);
        }
        
        // ===== INITIALIZE =====
        document.addEventListener('DOMContentLoaded', function() {
            updateHoursStatus();
            updateCartCount();
            refreshWeather();
            
            setInterval(updateCartCount, 5000);
            setInterval(updateHoursStatus, 60000);
            
            function handleResize() {
                const nav = document.querySelector('.nav-container');
                if (window.innerWidth > 768) {
                    nav.style.display = 'flex';
                }
            }
            
            window.addEventListener('resize', handleResize);
            
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
            
            document.querySelectorAll('.content-card').forEach(card => {
                observer.observe(card);
            });
        });
        
        // ===== ACCESSIBILITY FUNCTIONS =====
        function increaseTextSize() {
            document.body.style.fontSize = '20px';
            localStorage.setItem('textSize', 'large');
        }
        
        function toggleHighContrast() {
            document.body.classList.toggle('high-contrast');
            const isOn = document.body.classList.contains('high-contrast');
            localStorage.setItem('highContrast', isOn);
        }
        
        if (localStorage.getItem('textSize') === 'large') {
            document.body.style.fontSize = '20px';
        }
        
        if (localStorage.getItem('highContrast') === 'true') {
            document.body.classList.add('high-contrast');
        }
    </script>
</body>
</html>
