<?php
session_start();
require_once '../includes/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$admin_id = $_SESSION['admin_id'] ?? 1;
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_email = $_SESSION['admin_email'] ?? 'admin@yadangs.com';

// ===== CHECK FOR FIRST LOGIN (ADMIN MANUAL) =====
$show_admin_manual = false;

if (isset($_GET['first_login']) && $_GET['first_login'] == 1) {
    $show_admin_manual = true;
    unset($_SESSION['admin_first_login']);
} elseif (isset($_SESSION['admin_first_login']) && $_SESSION['admin_first_login'] === true) {
    $show_admin_manual = true;
    unset($_SESSION['admin_first_login']);
}

// Get today's date
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');

// ===== STATISTICS QUERIES =====

// Today's orders
$stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(order_date) = ?");
$stmt->execute([$today]);
$today_stats = $stmt->fetch();

// Yesterday's orders
$stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(order_date) = ?");
$stmt->execute([$yesterday]);
$yesterday_stats = $stmt->fetch();

// This week
$stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(order_date) >= ?");
$stmt->execute([$week_start]);
$week_stats = $stmt->fetch();

// This month
$stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(order_date) >= ?");
$stmt->execute([$month_start]);
$month_stats = $stmt->fetch();

// All time
$stmt = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM orders");
$all_stats = $stmt->fetch();

// Pending orders
$stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'");
$pending_count = $stmt->fetchColumn();

// Processing orders
$stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE status = 'processing'");
$processing_count = $stmt->fetchColumn();

// Completed orders today
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE status = 'completed' AND DATE(order_date) = ?");
$stmt->execute([$today]);
$completed_today = $stmt->fetchColumn();

// ===== FEEDBACK STATS =====
$feedback_stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new,
        SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) as replied
    FROM feedback
")->fetch();

// ===== CONTACT MESSAGES STATS =====
try {
    $columns = $pdo->query("SHOW COLUMNS FROM contact_messages")->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('status', $columns)) {
        $message_stats = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END) as urgent
            FROM contact_messages
        ")->fetch();
    } else {
        $message_stats = $pdo->query("
            SELECT 
                COUNT(*) as total,
                0 as unread,
                SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END) as urgent
            FROM contact_messages
        ")->fetch();
    }
} catch (PDOException $e) {
    $message_stats = [
        'total' => 0,
        'unread' => 0,
        'urgent' => 0
    ];
}

// ===== ANNOUNCEMENTS STATS =====
$announcement_stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
    FROM announcements
")->fetch();

// ===== RECENT ORDERS =====
$recent_orders = $pdo->query("
    SELECT o.*, 
           CASE WHEN o.processed_by IS NOT NULL THEN 'Admin' ELSE '-' END as processed_by_name
    FROM orders o 
    ORDER BY o.order_date DESC 
    LIMIT 10
")->fetchAll();

// ===== POPULAR ITEMS =====
$popular_items = $pdo->query("
    SELECT item_name, COUNT(*) as order_count, SUM(quantity) as total_quantity, SUM(subtotal) as revenue
    FROM order_items 
    GROUP BY item_name 
    ORDER BY total_quantity DESC 
    LIMIT 5
")->fetchAll();

// ===== ADMIN ACTIVITY TODAY =====
$stmt = $pdo->prepare("
    SELECT COUNT(*) as actions 
    FROM admin_activity_logs 
    WHERE admin_id = ? AND DATE(created_at) = ?
");
$stmt->execute([$admin_id, $today]);
$activity = $stmt->fetch();
$actions_today = $activity['actions'] ?? 0;

// ===== RECENT FEEDBACK =====
$recent_feedback = $pdo->query("
    SELECT * FROM feedback 
    WHERE status = 'new' 
    ORDER BY created_at DESC 
    LIMIT 5
")->fetchAll();

// ===== RECENT MESSAGES =====
try {
    $columns = $pdo->query("SHOW COLUMNS FROM contact_messages")->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('status', $columns)) {
        $recent_messages = $pdo->query("
            SELECT * FROM contact_messages 
            WHERE status = 'new' 
            ORDER BY 
                is_urgent DESC,
                created_at DESC 
            LIMIT 5
        ")->fetchAll();
    } else {
        $recent_messages = $pdo->query("
            SELECT * FROM contact_messages 
            ORDER BY 
                is_urgent DESC,
                created_at DESC 
            LIMIT 5
        ")->fetchAll();
    }
} catch (PDOException $e) {
    $recent_messages = [];
}

// Calculate percentage changes
$today_vs_yesterday = $yesterday_stats['total'] > 0 
    ? round(($today_stats['total'] - $yesterday_stats['total']) / $yesterday_stats['total'] * 100, 1) 
    : ($today_stats['total'] > 0 ? 100 : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Yadang's Time</title>
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
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --star-color: #FFD700;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a2c22 0%, #2d4638 100%);
            color: var(--text-light);
            min-height: 100vh;
        }
        
        /* ===== HEADER ===== */
        .main-header {
            background: rgba(45, 70, 56, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 3px solid var(--accent-color);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 4px 20px var(--shadow-color);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo-circle {
            width: 50px;
            height: 50px;
            background: var(--card-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--accent-color);
        }
        
        .logo-circle img {
            width: 30px;
            height: 30px;
        }
        
        .brand-text h1 {
            font-size: 1.5rem;
        }
        
        .brand-text p {
            color: var(--accent-color);
            font-size: 0.8rem;
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .admin-badge {
            background: rgba(250, 161, 143, 0.15);
            padding: 0.5rem 1.5rem;
            border-radius: 30px;
            border: 1px solid var(--accent-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .admin-avatar {
            width: 40px;
            height: 40px;
            background: var(--accent-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-text);
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .admin-details {
            display: flex;
            flex-direction: column;
        }
        
        .admin-name {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .admin-role {
            font-size: 0.7rem;
            color: var(--accent-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .logout-btn {
            background: transparent;
            border: 2px solid var(--accent-color);
            color: var(--accent-color);
            padding: 0.5rem 1.5rem;
            border-radius: 30px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .logout-btn:hover {
            background: var(--accent-color);
            color: var(--dark-text);
        }
        
        /* ===== MAIN CONTAINER ===== */
        .dashboard-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        /* ===== WELCOME SECTION ===== */
        .welcome-section {
            margin-bottom: 2rem;
        }
        
        .welcome-title {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #ffffff, var(--accent-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .welcome-date {
            color: var(--accent-color);
            font-size: 1.1rem;
        }
        
        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.2);
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
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .stat-title {
            color: rgba(232, 223, 208, 0.8);
            font-size: 0.9rem;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .stat-icon.orders { background: rgba(52, 152, 219, 0.2); color: var(--info-color); }
        .stat-icon.revenue { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        .stat-icon.pending { background: rgba(241, 196, 15, 0.2); color: var(--warning-color); }
        .stat-icon.feedback { background: rgba(255, 215, 0, 0.2); color: var(--star-color); }
        .stat-icon.messages { background: rgba(155, 89, 182, 0.2); color: #9b59b6; }
        .stat-icon.activity { background: rgba(250, 161, 143, 0.2); color: var(--accent-color); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.3rem;
        }
        
        .stat-change {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }
        
        .change-up { color: var(--success-color); }
        .change-down { color: var(--danger-color); }
        
        .stat-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            padding: 0.2rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        /* ===== QUICK STATS ROW ===== */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .quick-stat-item {
            background: rgba(26, 44, 34, 0.5);
            padding: 1rem;
            border-radius: 10px;
            text-align: center;
            border: 1px solid rgba(250, 161, 143, 0.1);
        }
        
        .quick-stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--accent-color);
        }
        
        .quick-stat-label {
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.7);
        }
        
        /* ===== TABLES SECTION ===== */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .table-card {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
        }
        
        .card-title {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-title i {
            color: var(--accent-color);
        }
        
        .badge {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            padding: 0.2rem 0.8rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: bold;
            margin-left: 0.5rem;
        }
        
        .view-all {
            color: var(--accent-color);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color 0.3s ease;
        }
        
        .view-all:hover {
            color: var(--light-accent);
        }
        
        /* ===== TABLES ===== */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .admin-table th {
            text-align: left;
            padding: 0.8rem 0.5rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
            color: var(--accent-color);
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .admin-table td {
            padding: 0.8rem 0.5rem;
            border-bottom: 1px solid rgba(232, 223, 208, 0.1);
        }
        
        .admin-table tr:hover td {
            background: rgba(250, 161, 143, 0.05);
        }
        
        .status-badge {
            padding: 0.2rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-completed { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        .status-pending { background: rgba(241, 196, 15, 0.2); color: var(--warning-color); }
        .status-processing { background: rgba(52, 152, 219, 0.2); color: var(--info-color); }
        .status-cancelled { background: rgba(231, 76, 60, 0.2); color: var(--danger-color); }
        .status-new { background: rgba(241, 196, 15, 0.2); color: var(--warning-color); }
        .status-replied { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        
        .urgent-badge {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-size: 0.65rem;
            font-weight: bold;
            margin-left: 0.3rem;
        }
        
        /* ===== POPULAR ITEMS LIST ===== */
        .popular-list {
            list-style: none;
        }
        
        .popular-item {
            margin-bottom: 1rem;
        }
        
        .item-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.3rem;
        }
        
        .item-name {
            font-weight: 500;
        }
        
        .item-stats {
            color: var(--accent-color);
            font-size: 0.9rem;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        /* ===== ACTIVITY FEED ===== */
        .activity-feed {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 0.8rem;
            border-bottom: 1px solid rgba(250, 161, 143, 0.1);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 35px;
            height: 35px;
            background: rgba(250, 161, 143, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent-color);
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-text {
            font-size: 0.9rem;
        }
        
        .activity-time {
            font-size: 0.7rem;
            color: rgba(232, 223, 208, 0.5);
        }
        
        /* ===== QUICK ACTIONS ===== */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .action-btn {
            background: var(--container-bg);
            border: 2px solid rgba(250, 161, 143, 0.3);
            border-radius: 12px;
            padding: 1.2rem 1rem;
            text-align: center;
            text-decoration: none;
            color: var(--text-light);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .action-btn:hover {
            border-color: var(--accent-color);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.2);
        }
        
        .action-btn i {
            font-size: 2rem;
            color: var(--accent-color);
            margin-bottom: 0.5rem;
        }
        
        .action-btn div {
            font-weight: 600;
            margin-bottom: 0.2rem;
        }
        
        .action-btn small {
            font-size: 0.7rem;
            color: rgba(232, 223, 208, 0.6);
        }
        
        .notification-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--danger-color);
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
        }
        
        /* ===== ADMIN MANUAL MODAL STYLES ===== */
        .admin-manual-modal {
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
            animation: adminModalFadeIn 0.3s ease;
        }
        
        @keyframes adminModalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes adminModalFadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        
        .admin-manual-content {
            background: #2d4638;
            border-radius: 20px;
            max-width: 600px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            border: 3px solid #FAA18F;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            animation: adminModalSlideIn 0.3s ease;
        }
        
        @keyframes adminModalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .admin-manual-header {
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
        
        .admin-manual-header h2 {
            color: #FAA18F;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        
        .admin-manual-close {
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
        
        .admin-manual-close:hover {
            background: rgba(250, 161, 143, 0.2);
            color: #FAA18F;
            transform: rotate(90deg);
        }
        
        .admin-manual-body {
            padding: 1.5rem;
        }
        
        .admin-welcome-text {
            color: #E8DFD0;
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .admin-manual-step {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .admin-manual-step:hover {
            background: rgba(250, 161, 143, 0.1);
            transform: translateX(5px);
        }
        
        .admin-step-icon {
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
        
        .admin-step-content h3 {
            color: #FAA18F;
            font-size: 1.1rem;
            margin-bottom: 0.3rem;
        }
        
        .admin-step-content p {
            color: #E8DFD0;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .admin-manual-tip {
            background: rgba(46, 204, 113, 0.15);
            border-left: 4px solid #2ecc71;
            padding: 1rem;
            border-radius: 10px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .admin-manual-tip i {
            color: #2ecc71;
            font-size: 1.2rem;
        }
        
        .admin-manual-tip strong {
            color: #2ecc71;
        }
        
        .admin-manual-tip span {
            color: #E8DFD0;
        }
        
        .admin-manual-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(250, 161, 143, 0.3);
            text-align: center;
        }
        
        .admin-btn-gotit {
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
        
        .admin-btn-gotit:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
        }
        
        .admin-manual-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .admin-manual-content::-webkit-scrollbar-track {
            background: rgba(26, 44, 34, 0.5);
            border-radius: 10px;
        }
        
        .admin-manual-content::-webkit-scrollbar-thumb {
            background: #FAA18F;
            border-radius: 10px;
        }
        
        /* ===== FOOTER ===== */
        .main-footer {
            background: linear-gradient(135deg, #1a2c22, #0f1913);
            color: var(--text-light);
            padding: 2rem;
            margin-top: 4rem;
            text-align: center;
            border-top: 1px solid rgba(250, 161, 143, 0.1);
        }
        
        .copyright {
            color: rgba(245, 233, 217, 0.6);
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-header {
                flex-direction: column;
                text-align: center;
                padding: 1rem;
            }
            
            .logo-section {
                flex-direction: column;
            }
            
            .admin-info {
                flex-direction: column;
                width: 100%;
            }
            
            .admin-badge {
                width: 100%;
                justify-content: center;
            }
            
            .logout-btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .admin-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .admin-manual-content {
                width: 95%;
                max-height: 90vh;
            }
            
            .admin-manual-step {
                flex-direction: column;
                text-align: center;
            }
            
            .admin-step-icon {
                margin: 0 auto;
            }
            
            .admin-manual-header h2 {
                font-size: 1.2rem;
            }
        }
        
        @media (max-width: 480px) {
            .quick-stats {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .welcome-title {
                font-size: 1.5rem;
            }
            
            .admin-manual-step {
                padding: 0.8rem;
            }
            
            .admin-step-content h3 {
                font-size: 1rem;
            }
            
            .admin-step-content p {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <!-- ADMIN MANUAL POPUP MODAL -->
    <?php if ($show_admin_manual): ?>
    <div id="adminManualModal" class="admin-manual-modal" style="display: flex;">
        <div class="admin-manual-content">
            <div class="admin-manual-header">
                <h2><i class="fas fa-chalkboard-teacher"></i> Welcome to Admin Dashboard!</h2>
                <button class="admin-manual-close" onclick="closeAdminManual()">&times;</button>
            </div>
            <div class="admin-manual-body">
                <p class="admin-welcome-text">Here's a quick guide to manage your Yadang's Time store:</p>
                
                <div class="admin-manual-step">
                    <div class="admin-step-icon"><i class="fas fa-utensils"></i></div>
                    <div class="admin-step-content">
                        <h3>1. Menu Items</h3>
                        <p>Add, edit, or remove menu items. Upload images, set prices, manage availability, and organize by categories.</p>
                    </div>
                </div>
                
                <div class="admin-manual-step">
                    <div class="admin-step-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="admin-step-content">
                        <h3>2. Manage Orders</h3>
                        <p>View all incoming orders, update order status (pending → processing → completed), and track customer orders in real-time.</p>
                    </div>
                </div>
                
                <div class="admin-manual-step">
                    <div class="admin-step-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="admin-step-content">
                        <h3>3. Reports</h3>
                        <p>View daily/weekly/monthly sales reports, top-selling items, revenue trends, payment method analytics, and peak hours.</p>
                    </div>
                </div>
                
                <div class="admin-manual-step">
                    <div class="admin-step-icon"><i class="fas fa-star"></i></div>
                    <div class="admin-step-content">
                        <h3>4. Feedback</h3>
                        <p>Review customer feedback and ratings. Respond to feedback to improve service quality and customer satisfaction.</p>
                    </div>
                </div>
                
                <div class="admin-manual-step">
                    <div class="admin-step-icon"><i class="fas fa-envelope"></i></div>
                    <div class="admin-step-content">
                        <h3>5. Contact Messages</h3>
                        <p>Read and respond to customer inquiries. Urgent messages are highlighted for immediate attention.</p>
                    </div>
                </div>
                
                <div class="admin-manual-step">
                    <div class="admin-step-icon"><i class="fas fa-bullhorn"></i></div>
                    <div class="admin-step-content">
                        <h3>6. Announcements</h3>
                        <p>Create and publish announcements that appear on the customer homepage. Set priority (urgent/normal) and expiry dates.</p>
                    </div>
                </div>
                
                <div class="admin-manual-tip">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Admin Tip:</strong> Use the quick action buttons on the dashboard for faster navigation to frequently used features! Check the stats cards for real-time business insights.
                </div>
            </div>
            <div class="admin-manual-footer">
                <button class="admin-btn-gotit" onclick="closeAdminManual()">Got it! Go to Dashboard <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <header class="main-header">
        <div class="logo-section">
            <div class="logo-circle">
                <img src="../images/yadangs_logo.png" alt="Yadang's Time">
            </div>
            <div class="brand-text">
                <h1>YADANG'S TIME</h1>
                <p>Admin Dashboard</p>
            </div>
        </div>
        
        <div class="admin-info">
            <div class="admin-badge">
                <div class="admin-avatar">
                    <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
                </div>
                <div class="admin-details">
                    <div class="admin-name"><?php echo $admin_name; ?></div>
                    <div class="admin-role">Administrator</div>
                </div>
            </div>
            
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>
    
    <!-- Main Dashboard -->
    <main class="dashboard-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1 class="welcome-title">Welcome back, <?php echo $admin_name; ?>! 👋</h1>
            <p class="welcome-date"><?php echo date('l, d F Y'); ?></p>
        </div>
        
        <!-- Main Stats Cards -->
        <div class="stats-grid">
            <!-- Today's Orders -->
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Today's Orders</div>
                    <div class="stat-icon orders">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $today_stats['count']; ?></div>
                <div class="stat-change <?php echo $today_vs_yesterday >= 0 ? 'change-up' : 'change-down'; ?>">
                    <i class="fas fa-arrow-<?php echo $today_vs_yesterday >= 0 ? 'up' : 'down'; ?>"></i>
                    <span><?php echo abs($today_vs_yesterday); ?>% from yesterday</span>
                </div>
                <small style="color: rgba(232, 223, 208, 0.6);">RM <?php echo number_format($today_stats['total'], 2); ?></small>
                <?php if ($completed_today > 0): ?>
                    <div class="stat-badge"><?php echo $completed_today; ?> completed</div>
                <?php endif; ?>
            </div>
            
            <!-- Pending Orders -->
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Orders Needing Attention</div>
                    <div class="stat-icon pending">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $pending_count + $processing_count; ?></div>
                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                    <span style="background: rgba(241, 196, 15, 0.2); color: var(--warning-color); padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.8rem;">
                        Pending: <?php echo $pending_count; ?>
                    </span>
                    <span style="background: rgba(52, 152, 219, 0.2); color: var(--info-color); padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.8rem;">
                        Processing: <?php echo $processing_count; ?>
                    </span>
                </div>
            </div>
            
            <!-- Feedback Stats -->
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Customer Feedback</div>
                    <div class="stat-icon feedback">
                        <i class="fas fa-star"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $feedback_stats['total'] ?? 0; ?></div>
                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                    <span style="background: rgba(241, 196, 15, 0.2); color: var(--warning-color); padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.8rem;">
                        New: <?php echo $feedback_stats['new'] ?? 0; ?>
                    </span>
                    <span style="background: rgba(46, 204, 113, 0.2); color: var(--success-color); padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.8rem;">
                        Replied: <?php echo $feedback_stats['replied'] ?? 0; ?>
                    </span>
                </div>
            </div>
            
            <!-- Contact Messages -->
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Contact Messages</div>
                    <div class="stat-icon messages">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $message_stats['total'] ?? 0; ?></div>
                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                    <?php if (($message_stats['unread'] ?? 0) > 0): ?>
                        <span style="background: rgba(241, 196, 15, 0.2); color: var(--warning-color); padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.8rem;">
                            Unread: <?php echo $message_stats['unread']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if (($message_stats['urgent'] ?? 0) > 0): ?>
                        <span style="background: rgba(231, 76, 60, 0.2); color: var(--danger-color); padding: 0.2rem 0.5rem; border-radius: 5px; font-size: 0.8rem;">
                            Urgent: <?php echo $message_stats['urgent']; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Quick Stats Row -->
        <div class="quick-stats">
            <div class="quick-stat-item">
                <div class="quick-stat-value">RM <?php echo number_format($week_stats['total'], 2); ?></div>
                <div class="quick-stat-label">This Week</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-value">RM <?php echo number_format($month_stats['total'], 2); ?></div>
                <div class="quick-stat-label">This Month</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-value"><?php echo $all_stats['count']; ?></div>
                <div class="quick-stat-label">Total Orders</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-value"><?php echo $announcement_stats['active'] ?? 0; ?> / <?php echo $announcement_stats['total'] ?? 0; ?></div>
                <div class="quick-stat-label">Active Announcements</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-value"><?php echo $actions_today; ?></div>
                <div class="quick-stat-label">Your Actions Today</div>
            </div>
        </div>
        
        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Recent Orders -->
            <div class="table-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        Recent Orders
                    </h3>
                    <a href="orders.php" class="view-all">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td><?php echo $order['order_number'] ?? 'ORD-' . str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo $order['customer_name'] ?? 'Guest'; ?></td>
                                <td>RM <?php echo number_format($order['total_amount'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $order['status']; ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Popular Items -->
            <div class="table-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-fire"></i>
                        Popular Items
                    </h3>
                </div>
                
                <div class="popular-list">
                    <?php 
                    $max_qty = !empty($popular_items) ? max(array_column($popular_items, 'total_quantity')) : 1;
                    foreach ($popular_items as $item): 
                    ?>
                        <div class="popular-item">
                            <div class="item-info">
                                <span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                <span class="item-stats"><?php echo $item['total_quantity']; ?> sold</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo ($item['total_quantity'] / $max_qty * 100); ?>%"></div>
                            </div>
                            <small style="color: rgba(232, 223, 208, 0.5);">RM <?php echo number_format($item['revenue'], 2); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Second Grid -->
        <div class="dashboard-grid">
            <!-- Recent Feedback -->
            <div class="table-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-comment-dots"></i>
                        New Feedback
                        <?php if (($feedback_stats['new'] ?? 0) > 0): ?>
                            <span class="badge"><?php echo $feedback_stats['new']; ?> new</span>
                        <?php endif; ?>
                    </h3>
                    <a href="feedback.php" class="view-all">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <?php if (empty($recent_feedback)): ?>
                    <p style="color: rgba(232, 223, 208, 0.5); text-align: center; padding: 2rem;">
                        No new feedback
                    </p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Rating</th>
                                <th>Preview</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_feedback as $fb): ?>
                                <tr>
                                    <td><?php echo $fb['is_anonymous'] ? 'Anonymous' : htmlspecialchars($fb['name']); ?></td>
                                    <td>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star" style="color: <?php echo $i <= $fb['rating'] ? 'var(--star-color)' : 'rgba(255,215,0,0.2)'; ?>; font-size: 0.8rem;"></i>
                                        <?php endfor; ?>
                                    </td>
                                    <td><?php echo substr(htmlspecialchars($fb['message']), 0, 30); ?>...</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="orders.php" class="action-btn">
                <i class="fas fa-shopping-cart"></i>
                <div>Manage Orders</div>
                <small>View & update orders</small>
                <?php if ($pending_count > 0): ?>
                    <span class="notification-badge"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="menu.php" class="action-btn">
                <i class="fas fa-utensils"></i>
                <div>Menu Items</div>
                <small>Add/edit menu</small>
            </a>
            
            <a href="reports.php" class="action-btn">
                <i class="fas fa-chart-line"></i>
                <div>Reports</div>
                <small>Sales & analytics</small>
            </a>
            
            <a href="feedback.php" class="action-btn">
                <i class="fas fa-comment-dots"></i>
                <div>Feedback</div>
                <small>View customer feedback</small>
                <?php if (($feedback_stats['new'] ?? 0) > 0): ?>
                    <span class="notification-badge"><?php echo $feedback_stats['new']; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="contact_messages.php" class="action-btn">
                <i class="fas fa-envelope"></i>
                <div>Contact Messages</div>
                <small>Customer messages</small>
                <?php if (($message_stats['urgent'] ?? 0) > 0): ?>
                    <span class="notification-badge" style="background: var(--danger-color);"><?php echo $message_stats['urgent']; ?></span>
                <?php elseif (($message_stats['total'] ?? 0) > 0): ?>
                    <span class="notification-badge" style="background: var(--info-color);"><?php echo $message_stats['total']; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="announcements.php" class="action-btn">
                <i class="fas fa-bullhorn"></i>
                <div>Announcements</div>
                <small>Update content</small>
                <?php if (($announcement_stats['active'] ?? 0) > 0): ?>
                    <span class="notification-badge" style="background: var(--success-color);"><?php echo $announcement_stats['active']; ?></span>
                <?php endif; ?>
            </a>
        </div>
    </main>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time Admin Panel</p>
            <p style="margin-top: 0.5rem; font-size: 0.8rem;">
                Logged in as <?php echo $admin_name; ?> • <?php echo date('d M Y H:i'); ?>
            </p>
        </div>
    </footer>
    
    <script>
        // ===== ADMIN MANUAL MODAL FUNCTIONS =====
        function closeAdminManual() {
            const modal = document.getElementById('adminManualModal');
            if (modal) {
                modal.style.animation = 'adminModalFadeOut 0.3s ease';
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            }
            localStorage.setItem('yadangs_admin_manual_seen', 'true');
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('adminManualModal');
            if (modal && modal.style.display === 'flex') {
                const content = modal.querySelector('.admin-manual-content');
                if (content && !content.contains(event.target)) {
                    closeAdminManual();
                }
            }
        });
        
        // Auto-refresh stats (optional)
        setTimeout(function() {
            location.reload();
        }, 300000); // Refresh every 5 minutes
    </script>
</body>
</html>
