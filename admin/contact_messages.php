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

$success_message = '';
$error_message = '';

// ===== HANDLE REPLY TO MESSAGE =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reply_message'])) {
    $message_id = $_POST['message_id'];
    $reply_text = trim($_POST['reply_text']);
    
    if (!empty($reply_text)) {
        $stmt = $pdo->prepare("
            UPDATE contact_messages 
            SET admin_reply = ?, replied_at = NOW(), replied_by = ?, status = 'replied'
            WHERE id = ?
        ");
        $stmt->execute([$reply_text, $admin_id, $message_id]);
        $success_message = "Reply sent successfully!";
    }
}

// ===== HANDLE MARK AS READ =====
if (isset($_GET['mark_read'])) {
    $id = $_GET['mark_read'];
    $stmt = $pdo->prepare("UPDATE contact_messages SET status = 'read' WHERE id = ?");
    $stmt->execute([$id]);
    $success_message = "Message marked as read";
}

// ===== HANDLE MARK AS UNREAD =====
if (isset($_GET['mark_unread'])) {
    $id = $_GET['mark_unread'];
    $stmt = $pdo->prepare("UPDATE contact_messages SET status = 'new' WHERE id = ?");
    $stmt->execute([$id]);
    $success_message = "Message marked as unread";
}

// ===== HANDLE DELETE MESSAGE =====
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM contact_messages WHERE id = ?");
    $stmt->execute([$id]);
    $success_message = "Message deleted successfully";
}

// ===== HANDLE BULK ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($selected_ids)) {
        $ids = implode(',', array_map('intval', $selected_ids));
        
        if ($action == 'delete') {
            $pdo->exec("DELETE FROM contact_messages WHERE id IN ($ids)");
            $success_message = "Selected messages deleted";
        } elseif ($action == 'mark_read') {
            $pdo->exec("UPDATE contact_messages SET status = 'read' WHERE id IN ($ids)");
            $success_message = "Selected messages marked as read";
        } elseif ($action == 'mark_unread') {
            $pdo->exec("UPDATE contact_messages SET status = 'new' WHERE id IN ($ids)");
            $success_message = "Selected messages marked as unread";
        }
    }
}

// ===== GET MESSAGE FOR VIEWING/REPLY =====
$view_message = null;
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM contact_messages WHERE id = ?");
    $stmt->execute([$_GET['view']]);
    $view_message = $stmt->fetch();
}

// ===== FILTER PARAMETERS =====
$status_filter = $_GET['status'] ?? 'all';
$urgent_filter = isset($_GET['urgent']) ? $_GET['urgent'] : 'all';

// ===== BUILD QUERY =====
$query = "SELECT * FROM contact_messages WHERE 1=1";
$params = [];

if ($status_filter == 'read') {
    $query .= " AND status = 'read'";
} elseif ($status_filter == 'unread') {
    $query .= " AND status = 'new'";
} elseif ($status_filter == 'replied') {
    $query .= " AND status = 'replied'";
}

if ($urgent_filter == 'urgent') {
    $query .= " AND is_urgent = 1";
} elseif ($urgent_filter == 'normal') {
    $query .= " AND is_urgent = 0";
}

$query .= " ORDER BY 
    CASE 
        WHEN is_urgent = 1 THEN 1 
        WHEN status = 'new' THEN 2 
        ELSE 3 
    END,
    created_at DESC";

$messages = $pdo->query($query)->fetchAll();

// ===== GET STATS =====
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END) as urgent,
        SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) as replied
    FROM contact_messages
")->fetch();

// Get admin names for replies
$admins = $pdo->query("SELECT id, full_name FROM admin")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Messages | Yadang's Time Admin</title>
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
            --gradient-dark: linear-gradient(135deg, #1a2c22 0%, #2d4638 100%);
            --gradient-light: linear-gradient(135deg, #2d4638 0%, #3d5c49 100%);
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
        }
        
        .admin-role {
            font-size: 0.8rem;
            color: var(--accent-color);
            text-transform: uppercase;
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
        }
        
        .logout-btn:hover {
            background: var(--accent-color);
            color: var(--dark-text);
        }
        
        /* ===== MAIN CONTAINER ===== */
        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title {
            font-size: 2rem;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-title i {
            color: var(--accent-color);
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
        }
        
        .btn-secondary {
            background: transparent;
            border: 2px solid var(--accent-color);
            color: var(--accent-color);
            padding: 0.8rem 1.5rem;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-secondary:hover {
            background: var(--accent-color);
            color: var(--dark-text);
        }
        
        /* ===== ALERT ===== */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        .alert.success {
            background: rgba(46, 204, 113, 0.2);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }
        
        .alert.error {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
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
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .stat-title {
            color: rgba(232, 223, 208, 0.7);
            font-size: 0.9rem;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .stat-icon.total { background: rgba(52, 152, 219, 0.2); color: var(--info-color); }
        .stat-icon.unread { background: rgba(241, 196, 15, 0.2); color: var(--warning-color); }
        .stat-icon.urgent { background: rgba(231, 76, 60, 0.2); color: var(--danger-color); }
        .stat-icon.replied { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
        }
        
        /* ===== FILTER SECTION ===== */
        .filter-section {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
        }
        
        .filter-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-color);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .filter-group {
            margin-bottom: 1rem;
        }
        
        .filter-label {
            display: block;
            margin-bottom: 0.3rem;
            color: var(--accent-color);
            font-size: 0.9rem;
        }
        
        .filter-select {
            width: 100%;
            padding: 0.8rem;
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid rgba(250, 161, 143, 0.3);
            border-radius: 8px;
            color: var(--text-light);
        }
        
        .filter-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .filter-btn {
            background: var(--accent-color);
            color: var(--dark-text);
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .reset-btn {
            background: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
        }
        
        /* ===== BULK ACTIONS ===== */
        .bulk-actions {
            background: var(--container-bg);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .bulk-checkbox {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .bulk-checkbox input {
            width: 18px;
            height: 18px;
            accent-color: var(--accent-color);
        }
        
        .bulk-select {
            padding: 0.5rem;
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid rgba(250, 161, 143, 0.3);
            border-radius: 5px;
            color: var(--text-light);
        }
        
        .bulk-btn {
            background: var(--accent-color);
            color: var(--dark-text);
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
        }
        
        /* ===== MESSAGES GRID ===== */
        .messages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .message-card {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            position: relative;
            transition: all 0.3s ease;
        }
        
        .message-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.2);
        }
        
        .message-card.unread {
            border-left: 4px solid var(--warning-color);
            background: rgba(241, 196, 15, 0.05);
        }
        
        .message-card.urgent {
            border-right: 4px solid var(--danger-color);
        }
        
        .message-card.replied {
            border-bottom: 4px solid var(--success-color);
        }
        
        .message-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .sender-info {
            display: flex;
            flex-direction: column;
        }
        
        .sender-name {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--accent-color);
        }
        
        .sender-email {
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.6);
        }
        
        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-size: 0.65rem;
            font-weight: bold;
        }
        
        .status-new {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
        }
        
        .status-replied {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }
        
        .status-read {
            background: rgba(52, 152, 219, 0.2);
            color: var(--info-color);
        }
        
        .urgent-badge {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .phone-info {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .phone-info i {
            color: var(--accent-color);
            margin-right: 5px;
        }
        
        .subject-info {
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .message-content {
            background: rgba(26, 44, 34, 0.5);
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            max-height: 150px;
            overflow-y: auto;
            border-left: 3px solid var(--accent-color);
        }
        
        .message-content::-webkit-scrollbar {
            width: 4px;
        }
        
        .message-content::-webkit-scrollbar-track {
            background: rgba(232,223,208,0.1);
        }
        
        .message-content::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 4px;
        }
        
        .admin-reply {
            background: rgba(46, 204, 113, 0.1);
            border-left: 4px solid var(--success-color);
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 8px;
        }
        
        .reply-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            color: var(--success-color);
        }
        
        .reply-content {
            color: rgba(232, 223, 208, 0.9);
            font-style: italic;
        }
        
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.6);
        }
        
        .timestamp i {
            margin-right: 3px;
            color: var(--accent-color);
        }
        
        .message-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid;
            font-size: 0.9rem;
        }
        
        .btn-icon.read {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-icon.read:hover {
            background: var(--success-color);
            color: white;
        }
        
        .btn-icon.unread {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
            border-color: var(--warning-color);
        }
        
        .btn-icon.unread:hover {
            background: var(--warning-color);
            color: white;
        }
        
        .btn-icon.reply {
            background: rgba(52, 152, 219, 0.2);
            color: var(--info-color);
            border-color: var(--info-color);
        }
        
        .btn-icon.reply:hover {
            background: var(--info-color);
            color: white;
        }
        
        .btn-icon.delete {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        .btn-icon.delete:hover {
            background: var(--danger-color);
            color: white;
        }
        
        /* ===== MODAL ===== */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 25, 19, 0.95);
            backdrop-filter: blur(5px);
            display: <?php echo $view_message ? 'flex' : 'none'; ?>;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }
        
        .modal-content {
            background: var(--gradient-light);
            border-radius: 15px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 2rem;
            border: 2px solid var(--accent-color);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
        }
        
        .modal-title {
            font-size: 1.8rem;
            color: var(--accent-color);
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 2rem;
            cursor: pointer;
            transition: color 0.3s ease;
            text-decoration: none;
        }
        
        .modal-close:hover {
            color: var(--accent-color);
        }
        
        .message-detail {
            background: rgba(26, 44, 34, 0.5);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .reply-textarea {
            width: 100%;
            padding: 1rem;
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid rgba(250, 161, 143, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 1rem;
            resize: vertical;
            min-height: 150px;
            margin-bottom: 1rem;
        }
        
        .modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .modal-btn {
            flex: 1;
            padding: 1rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
        }
        
        .modal-btn-primary {
            background: var(--accent-color);
            color: var(--dark-text);
        }
        
        .modal-btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
        }
        
        .modal-btn-secondary {
            background: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
        }
        
        .modal-btn-secondary:hover {
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
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-header {
                flex-direction: column;
                text-align: center;
            }
            
            .admin-info {
                flex-direction: column;
                width: 100%;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .messages-grid {
                grid-template-columns: 1fr;
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .modal-actions {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="logo-section">
            <div class="logo-circle">
                <img src="../images/yadangs_logo.png" alt="Yadang's Time">
            </div>
            <div class="brand-text">
                <h1>YADANG'S TIME</h1>
                <p>Contact Messages</p>
            </div>
        </div>
        
        <div class="admin-info">
            <div class="admin-badge">
                <div class="admin-avatar">
                    <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight: 600;"><?php echo $admin_name; ?></div>
                    <div class="admin-role">Administrator</div>
                </div>
            </div>
            
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>
    
    <!-- Main Container -->
    <main class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-envelope"></i>
                Contact Messages
            </h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if ($success_message): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Messages</div>
                    <div class="stat-icon total">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Unread</div>
                    <div class="stat-icon unread">
                        <i class="fas fa-envelope-open"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['unread'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Urgent</div>
                    <div class="stat-icon urgent">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['urgent'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Replied</div>
                    <div class="stat-icon replied">
                        <i class="fas fa-reply"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['replied'] ?? 0; ?></div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <h3 class="filter-title">
                <i class="fas fa-filter"></i> Filter Messages
            </h3>
            
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-select">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="unread" <?php echo $status_filter == 'unread' ? 'selected' : ''; ?>>Unread</option>
                            <option value="read" <?php echo $status_filter == 'read' ? 'selected' : ''; ?>>Read</option>
                            <option value="replied" <?php echo $status_filter == 'replied' ? 'selected' : ''; ?>>Replied</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Priority</label>
                        <select name="urgent" class="filter-select">
                            <option value="all" <?php echo $urgent_filter == 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="urgent" <?php echo $urgent_filter == 'urgent' ? 'selected' : ''; ?>>Urgent Only</option>
                            <option value="normal" <?php echo $urgent_filter == 'normal' ? 'selected' : ''; ?>>Normal Only</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="contact_messages.php" class="reset-btn">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Bulk Actions -->
        <form method="POST" id="bulkForm">
            <div class="bulk-actions">
                <div class="bulk-checkbox">
                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                    <label for="selectAll">Select All</label>
                </div>
                
                <select name="bulk_action" class="bulk-select">
                    <option value="">Bulk Actions</option>
                    <option value="mark_read">Mark as Read</option>
                    <option value="mark_unread">Mark as Unread</option>
                    <option value="delete">Delete</option>
                </select>
                
                <button type="submit" class="bulk-btn">Apply</button>
            </div>
        
            <!-- Messages Grid -->
            <div class="messages-grid">
                <?php if (empty($messages)): ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 4rem; background: var(--container-bg); border-radius: 15px;">
                        <i class="fas fa-inbox" style="font-size: 4rem; color: rgba(250,161,143,0.3); margin-bottom: 1rem;"></i>
                        <h3>No messages found</h3>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <div class="message-card 
                            <?php echo $msg['status'] == 'new' ? 'unread' : ''; ?> 
                            <?php echo $msg['is_urgent'] ? 'urgent' : ''; ?>
                            <?php echo $msg['status'] == 'replied' ? 'replied' : ''; ?>">
                            
                            <div style="position: absolute; top: 10px; right: 10px;">
                                <input type="checkbox" name="selected_ids[]" value="<?php echo $msg['id']; ?>" class="message-checkbox">
                            </div>
                            
                            <div class="card-header">
                                <div class="sender-info">
                                    <span class="sender-name"><?php echo htmlspecialchars($msg['name']); ?></span>
                                    <span class="sender-email"><?php echo htmlspecialchars($msg['email']); ?></span>
                                </div>
                                <div style="display: flex; gap: 0.3rem;">
                                    <?php if ($msg['status'] == 'new'): ?>
                                        <span class="status-badge status-new">NEW</span>
                                    <?php elseif ($msg['status'] == 'replied'): ?>
                                        <span class="status-badge status-replied">REPLIED</span>
                                    <?php endif; ?>
                                    <?php if ($msg['is_urgent']): ?>
                                        <span class="urgent-badge">URGENT</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($msg['phone'])): ?>
                                <div class="phone-info">
                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($msg['phone']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($msg['subject'])): ?>
                                <div class="subject-info">
                                    <i class="fas fa-tag" style="color: var(--accent-color);"></i>
                                    <?php echo htmlspecialchars($msg['subject']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="message-content">
                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            </div>
                            
                            <?php if (!empty($msg['admin_reply'])): ?>
                                <div class="admin-reply">
                                    <div class="reply-header">
                                        <i class="fas fa-reply"></i>
                                        <span>Admin Reply</span>
                                        <span style="font-size: 0.7rem; margin-left: auto;">
                                            <?php echo date('d/m/Y H:i', strtotime($msg['replied_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="reply-content">
                                        <?php echo nl2br(htmlspecialchars($msg['admin_reply'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="card-footer">
                                <span class="timestamp">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($msg['created_at'])); ?>
                                </span>
                                <div class="message-actions">
                                    <?php if ($msg['status'] == 'new'): ?>
                                        <a href="?mark_read=<?php echo $msg['id']; ?>" class="btn-icon read" title="Mark as Read">
                                            <i class="fas fa-check"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="?mark_unread=<?php echo $msg['id']; ?>" class="btn-icon unread" title="Mark as Unread">
                                            <i class="fas fa-envelope"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="?view=<?php echo $msg['id']; ?>" class="btn-icon reply" title="Reply">
                                        <i class="fas fa-reply"></i>
                                    </a>
                                    
                                    <a href="?delete=<?php echo $msg['id']; ?>" class="btn-icon delete" title="Delete" 
                                       onclick="return confirm('Delete this message?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </form>
    </main>
    
    <!-- Reply Modal -->
    <?php if ($view_message): ?>
    <div class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Reply to Message</h2>
                <a href="contact_messages.php" class="modal-close">&times;</a>
            </div>
            
            <div class="message-detail">
                <p><strong>From:</strong> <?php echo htmlspecialchars($view_message['name']); ?> (<?php echo htmlspecialchars($view_message['email']); ?>)</p>
                <?php if (!empty($view_message['phone'])): ?>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($view_message['phone']); ?></p>
                <?php endif; ?>
                <?php if (!empty($view_message['subject'])): ?>
                    <p><strong>Subject:</strong> <?php echo htmlspecialchars($view_message['subject']); ?></p>
                <?php endif; ?>
                <p><strong>Message:</strong></p>
                <p><?php echo nl2br(htmlspecialchars($view_message['message'])); ?></p>
            </div>
            
            <?php if (!empty($view_message['admin_reply'])): ?>
                <div class="admin-reply" style="margin-bottom: 1rem;">
                    <div class="reply-header">
                        <i class="fas fa-reply"></i>
                        <span>Previous Reply</span>
                    </div>
                    <div class="reply-content">
                        <?php echo nl2br(htmlspecialchars($view_message['admin_reply'])); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="message_id" value="<?php echo $view_message['id']; ?>">
                
                <label class="form-label">Your Reply</label>
                <textarea name="reply_text" class="reply-textarea" placeholder="Type your reply here..."></textarea>
                
                <div class="modal-actions">
                    <a href="contact_messages.php" class="modal-btn modal-btn-secondary">Cancel</a>
                    <button type="submit" name="reply_message" class="modal-btn modal-btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Reply
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time Admin Panel</p>
            <p style="margin-top: 0.5rem;">
                <i class="fas fa-envelope" style="color: var(--accent-color);"></i>
                <?php echo $stats['unread'] ?? 0; ?> unread • <?php echo $stats['urgent'] ?? 0; ?> urgent
            </p>
        </div>
    </footer>
    
    <script>
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.message-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        }
        
        // Auto-hide alerts
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.querySelector('.modal-overlay')) {
                window.location.href = 'contact_messages.php';
            }
        });
    </script>
</body>
</html>
