<?php
session_start();
require_once '../includes/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$admin_id = $_SESSION['admin_id'] ?? 0;
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';

$success_message = '';
$error_message = '';

// ===== HANDLE ADD ANNOUNCEMENT =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $type = $_POST['type'] ?? 'general';
    $priority = $_POST['priority'] ?? 'normal';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $display_order = intval($_POST['display_order'] ?? 0);
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    
    if (empty($title) || empty($content)) {
        $error_message = "Title and content are required";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO announcements (title, content, type, priority, is_active, display_order, expiry_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$title, $content, $type, $priority, $is_active, $display_order, $expiry_date, $admin_id])) {
            $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action) VALUES (?, ?)");
            $log->execute([$admin_id, "Added announcement: $title"]);
            
            $success_message = "Announcement added successfully!";
        } else {
            $error_message = "Failed to add announcement";
        }
    }
}

// ===== HANDLE EDIT ANNOUNCEMENT =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_announcement'])) {
    $id = $_POST['announcement_id'];
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $type = $_POST['type'] ?? 'general';
    $priority = $_POST['priority'] ?? 'normal';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $display_order = intval($_POST['display_order'] ?? 0);
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    
    if (empty($title) || empty($content)) {
        $error_message = "Title and content are required";
    } else {
        $stmt = $pdo->prepare("
            UPDATE announcements 
            SET title = ?, content = ?, type = ?, priority = ?, is_active = ?, display_order = ?, expiry_date = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([$title, $content, $type, $priority, $is_active, $display_order, $expiry_date, $id])) {
            $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action) VALUES (?, ?)");
            $log->execute([$admin_id, "Updated announcement: $title"]);
            
            $success_message = "Announcement updated successfully!";
        } else {
            $error_message = "Failed to update announcement";
        }
    }
}

// ===== HANDLE DELETE ANNOUNCEMENT =====
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    $stmt = $pdo->prepare("SELECT title FROM announcements WHERE id = ?");
    $stmt->execute([$id]);
    $title = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
    if ($stmt->execute([$id])) {
        $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action) VALUES (?, ?)");
        $log->execute([$admin_id, "Deleted announcement: $title"]);
        
        $success_message = "Announcement deleted successfully!";
    }
}

// ===== HANDLE TOGGLE ACTIVE =====
if (isset($_GET['toggle'])) {
    $id = $_GET['toggle'];
    
    $stmt = $pdo->prepare("UPDATE announcements SET is_active = NOT is_active WHERE id = ?");
    if ($stmt->execute([$id])) {
        $success_message = "Announcement status updated";
    }
}

// ===== GET ALL ANNOUNCEMENTS (FIXED QUERY - NO priority IN ORDER) =====
try {
    // Try with priority first
    $announcements = $pdo->query("
        SELECT a.*, ad.full_name as created_by_name 
        FROM announcements a
        LEFT JOIN admin ad ON a.created_by = ad.id
        ORDER BY 
            a.display_order ASC,
            a.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    // Fallback if priority column doesn't exist
    $announcements = $pdo->query("
        SELECT a.*, ad.full_name as created_by_name 
        FROM announcements a
        LEFT JOIN admin ad ON a.created_by = ad.id
        ORDER BY a.created_at DESC
    ")->fetchAll();
}

// ===== GET ANNOUNCEMENT FOR EDITING =====
$edit_announcement = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_announcement = $stmt->fetch();
}

// ===== FILTER PARAMETERS =====
$type_filter = $_GET['type'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Filter announcements for display
$filtered_announcements = array_filter($announcements, function($a) use ($type_filter, $status_filter) {
    if ($type_filter != 'all' && $a['type'] != $type_filter) {
        return false;
    }
    if ($status_filter == 'active' && !$a['is_active']) {
        return false;
    }
    if ($status_filter == 'inactive' && $a['is_active']) {
        return false;
    }
    return true;
});

// Stats
$total = count($announcements);
$active = count(array_filter($announcements, fn($a) => $a['is_active']));
$expired = count(array_filter($announcements, function($a) {
    return $a['expiry_date'] && strtotime($a['expiry_date']) < time();
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Yadang's Time Admin</title>
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
        
        .btn-primary {
            background: var(--accent-color);
            color: var(--dark-text);
            border: none;
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
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
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
        .stat-icon.active { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        .stat-icon.expired { background: rgba(231, 76, 60, 0.2); color: var(--danger-color); }
        
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
            display: inline-block;
        }
        
        /* ===== ANNOUNCEMENTS GRID ===== */
        .announcements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .announcement-card {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .announcement-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.2);
        }
        
        .announcement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .announcement-card.inactive {
            opacity: 0.7;
            border-color: #666;
        }
        
        .announcement-card.inactive::before {
            background: #666;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .type-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .type-promo { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        .type-job { background: rgba(52, 152, 219, 0.2); color: var(--info-color); }
        .type-update { background: rgba(155, 89, 182, 0.2); color: #9b59b6; }
        .type-general { background: rgba(241, 196, 15, 0.2); color: var(--warning-color); }
        
        .priority-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .priority-urgent { background: rgba(231, 76, 60, 0.2); color: var(--danger-color); }
        .priority-high { background: rgba(241, 196, 15, 0.2); color: var(--warning-color); }
        .priority-normal { background: rgba(52, 152, 219, 0.2); color: var(--info-color); }
        .priority-low { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        
        .card-title {
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            padding-right: 2rem;
        }
        
        .card-content {
            color: rgba(232, 223, 208, 0.9);
            margin-bottom: 1rem;
            line-height: 1.6;
        }
        
        .card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: rgba(232, 223, 208, 0.6);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .meta-item i {
            color: var(--accent-color);
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }
        
        .status-inactive {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
        }
        
        .status-expired {
            background: rgba(149, 165, 166, 0.2);
            color: #95a5a6;
        }
        
        .card-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(250, 161, 143, 0.1);
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 1rem;
            text-decoration: none;
        }
        
        .btn-icon.edit {
            background: rgba(52, 152, 219, 0.2);
            color: var(--info-color);
            border: 1px solid var(--info-color);
        }
        
        .btn-icon.edit:hover {
            background: var(--info-color);
            color: white;
        }
        
        .btn-icon.toggle {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }
        
        .btn-icon.toggle:hover {
            background: var(--success-color);
            color: white;
        }
        
        .btn-icon.delete {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }
        
        .btn-icon.delete:hover {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-icon.toggle.inactive {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }
        
        .btn-icon.toggle.inactive:hover {
            background: var(--warning-color);
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
            display: <?php echo (isset($_GET['add']) || $edit_announcement) ? 'flex' : 'none'; ?>;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }
        
        .modal-content {
            background: var(--container-bg);
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
        
        /* ===== FORM STYLES ===== */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            color: var(--accent-color);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.8rem;
            background: rgba(26, 44, 34, 0.7);
            border: 2px solid rgba(250, 161, 143, 0.2);
            border-radius: 8px;
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
            min-height: 100px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
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
            
            .header-actions {
                width: 100%;
                flex-direction: column;
            }
            
            .btn-secondary, .btn-primary {
                width: 100%;
                justify-content: center;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .announcements-grid {
                grid-template-columns: 1fr;
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
                <p>Announcements</p>
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
                <i class="fas fa-bullhorn"></i>
                Announcements
            </h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <a href="?add=1" class="btn-primary">
                    <i class="fas fa-plus"></i> New Announcement
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
                    <div class="stat-title">Total</div>
                    <div class="stat-icon total">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $total; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Active</div>
                    <div class="stat-icon active">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $active; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Expired</div>
                    <div class="stat-icon expired">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $expired; ?></div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <h3 class="filter-title">
                <i class="fas fa-filter"></i> Filter Announcements
            </h3>
            
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Type</label>
                        <select name="type" class="filter-select">
                            <option value="all" <?php echo $type_filter == 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="promo" <?php echo $type_filter == 'promo' ? 'selected' : ''; ?>>🎉 Promo</option>
                            <option value="job" <?php echo $type_filter == 'job' ? 'selected' : ''; ?>>👥 Part-time Jobs</option>
                            <option value="update" <?php echo $type_filter == 'update' ? 'selected' : ''; ?>>☕ Updates</option>
                            <option value="general" <?php echo $type_filter == 'general' ? 'selected' : ''; ?>>📢 General</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-select">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="announcements.php" class="reset-btn">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Announcements Grid -->
        <div class="announcements-grid">
            <?php if (empty($filtered_announcements)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 4rem; background: var(--container-bg); border-radius: 15px;">
                    <i class="fas fa-bullhorn" style="font-size: 4rem; color: rgba(250,161,143,0.3); margin-bottom: 1rem;"></i>
                    <h3>No announcements found</h3>
                    <p style="color: rgba(232,223,208,0.6);">Click "New Announcement" to create one</p>
                </div>
            <?php else: ?>
                <?php foreach ($filtered_announcements as $a): ?>
                    <?php 
                    $is_expired = $a['expiry_date'] && strtotime($a['expiry_date']) < time();
                    $status_class = !$a['is_active'] ? 'inactive' : ($is_expired ? 'expired' : 'active');
                    ?>
                    <div class="announcement-card <?php echo !$a['is_active'] ? 'inactive' : ''; ?>">
                        <div class="card-header">
                            <div>
                                <span class="type-badge type-<?php echo $a['type']; ?>">
                                    <?php 
                                    switch($a['type']) {
                                        case 'promo': echo '🎉 Promo'; break;
                                        case 'job': echo '👥 Part-time'; break;
                                        case 'update': echo '☕ Update'; break;
                                        default: echo '📢 General';
                                    }
                                    ?>
                                </span>
                                <?php if (isset($a['priority'])): ?>
                                    <span class="priority-badge priority-<?php echo $a['priority']; ?>">
                                        <?php echo ucfirst($a['priority']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?php echo $status_class; ?>">
                                <?php 
                                if (!$a['is_active']) echo 'Inactive';
                                elseif ($is_expired) echo 'Expired';
                                else echo 'Active';
                                ?>
                            </span>
                        </div>
                        
                        <h3 class="card-title"><?php echo htmlspecialchars($a['title']); ?></h3>
                        
                        <div class="card-content">
                            <?php echo nl2br(htmlspecialchars($a['content'])); ?>
                        </div>
                        
                        <div class="card-meta">
                            <span class="meta-item">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo date('d/m/Y', strtotime($a['created_at'])); ?>
                            </span>
                            <?php if ($a['expiry_date']): ?>
                                <span class="meta-item">
                                    <i class="fas fa-hourglass-end"></i>
                                    Expires: <?php echo date('d/m/Y', strtotime($a['expiry_date'])); ?>
                                </span>
                            <?php endif; ?>
                            <span class="meta-item">
                                <i class="fas fa-sort-numeric-up-alt"></i>
                                Order: <?php echo $a['display_order']; ?>
                            </span>
                        </div>
                        
                        <div class="card-actions">
                            <a href="?edit=<?php echo $a['id']; ?>" class="btn-icon edit" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="?toggle=<?php echo $a['id']; ?>" class="btn-icon toggle <?php echo !$a['is_active'] ? 'inactive' : ''; ?>" 
                               title="<?php echo $a['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                <i class="fas fa-power-off"></i>
                            </a>
                            <a href="?delete=<?php echo $a['id']; ?>" class="btn-icon delete" title="Delete" 
                               onclick="return confirm('Delete this announcement?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                        
                        <?php if ($a['created_by_name']): ?>
                            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: rgba(232,223,208,0.5);">
                                <i class="fas fa-user"></i> By: <?php echo $a['created_by_name']; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- Add Announcement Modal -->
    <?php if (isset($_GET['add'])): ?>
    <div class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">New Announcement</h2>
                <a href="announcements.php" class="modal-close">&times;</a>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-input" required placeholder="e.g., 🎉 New Year Promo!">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Content *</label>
                    <textarea name="content" class="form-textarea" required placeholder="Write your announcement here..."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="general">📢 General</option>
                            <option value="promo">🎉 Promo</option>
                            <option value="job">👥 Part-time Job</option>
                            <option value="update">☕ Update</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-input" value="0" min="0">
                        <small style="color: rgba(232,223,208,0.5);">Lower numbers appear first</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Expiry Date (Optional)</label>
                        <input type="datetime-local" name="expiry_date" class="form-input">
                        <small style="color: rgba(232,223,208,0.5);">Leave empty for no expiry</small>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    <label for="is_active">Active (show on homepage)</label>
                </div>
                
                <div class="modal-actions">
                    <a href="announcements.php" class="modal-btn modal-btn-secondary">Cancel</a>
                    <button type="submit" name="add_announcement" class="modal-btn modal-btn-primary">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Edit Announcement Modal -->
    <?php if ($edit_announcement): ?>
    <div class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Edit Announcement</h2>
                <a href="announcements.php" class="modal-close">&times;</a>
            </div>
            
            <form method="POST">
                <input type="hidden" name="announcement_id" value="<?php echo $edit_announcement['id']; ?>">
                
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-input" 
                           value="<?php echo htmlspecialchars($edit_announcement['title']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Content *</label>
                    <textarea name="content" class="form-textarea" required><?php echo htmlspecialchars($edit_announcement['content']); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="general" <?php echo $edit_announcement['type'] == 'general' ? 'selected' : ''; ?>>📢 General</option>
                            <option value="promo" <?php echo $edit_announcement['type'] == 'promo' ? 'selected' : ''; ?>>🎉 Promo</option>
                            <option value="job" <?php echo $edit_announcement['type'] == 'job' ? 'selected' : ''; ?>>👥 Part-time Job</option>
                            <option value="update" <?php echo $edit_announcement['type'] == 'update' ? 'selected' : ''; ?>>☕ Update</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="low" <?php echo $edit_announcement['priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="normal" <?php echo $edit_announcement['priority'] == 'normal' ? 'selected' : ''; ?>>Normal</option>
                            <option value="high" <?php echo $edit_announcement['priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $edit_announcement['priority'] == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-input" 
                               value="<?php echo $edit_announcement['display_order']; ?>" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Expiry Date (Optional)</label>
                        <?php 
                        $expiry = $edit_announcement['expiry_date'] ? date('Y-m-d\TH:i', strtotime($edit_announcement['expiry_date'])) : '';
                        ?>
                        <input type="datetime-local" name="expiry_date" class="form-input" value="<?php echo $expiry; ?>">
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active" <?php echo $edit_announcement['is_active'] ? 'checked' : ''; ?>>
                    <label for="is_active">Active (show on homepage)</label>
                </div>
                
                <div class="modal-actions">
                    <a href="announcements.php" class="modal-btn modal-btn-secondary">Cancel</a>
                    <button type="submit" name="edit_announcement" class="modal-btn modal-btn-primary">
                        <i class="fas fa-save"></i> Update
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
                <i class="fas fa-bullhorn" style="color: var(--accent-color);"></i>
                <?php echo $active; ?> active announcements
            </p>
        </div>
    </footer>
    
    <script>
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
                window.location.href = 'announcements.php';
            }
        });
    </script>
</body>
</html>
