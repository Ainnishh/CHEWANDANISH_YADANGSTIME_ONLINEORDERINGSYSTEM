<?php
session_start();
require_once '../includes/db.php';

// ===== CHECK ADMIN LOGIN =====
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Dapatkan admin info dari session
$admin_id = $_SESSION['admin_id'] ?? 0;
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';

if ($admin_id == 0) {
    header('Location: login.php');
    exit();
}

$success_message = '';
$error_message = '';

// ===== HANDLE MARK AS READ/UNREAD =====
if (isset($_GET['mark_read'])) {
    $feedback_id = $_GET['mark_read'];
    $stmt = $pdo->prepare("UPDATE feedback SET status = 'read' WHERE id = ?");
    $stmt->execute([$feedback_id]);
    $success_message = "Feedback marked as read";
}

if (isset($_GET['mark_unread'])) {
    $feedback_id = $_GET['mark_unread'];
    $stmt = $pdo->prepare("UPDATE feedback SET status = 'new' WHERE id = ?");
    $stmt->execute([$feedback_id]);
    $success_message = "Feedback marked as unread";
}

// ===== HANDLE DELETE FEEDBACK =====
if (isset($_GET['delete'])) {
    $feedback_id = $_GET['delete'];
    
    // Delete related testimonials first
    $stmt = $pdo->prepare("DELETE FROM testimonials WHERE feedback_id = ?");
    $stmt->execute([$feedback_id]);
    
    // Delete feedback
    $stmt = $pdo->prepare("DELETE FROM feedback WHERE id = ?");
    $stmt->execute([$feedback_id]);
    
    // Log activity
    $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action) VALUES (?, ?)");
    $log->execute([$admin_id, "Deleted feedback ID: $feedback_id"]);
    
    $success_message = "Feedback deleted successfully";
}

// ===== HANDLE REPLY TO FEEDBACK =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reply_feedback'])) {
    $feedback_id = $_POST['feedback_id'];
    $reply_message = trim($_POST['reply_message']);
    
    if (!empty($reply_message)) {
        // Check if replied_by column exists
        $check = $pdo->query("SHOW COLUMNS FROM feedback LIKE 'replied_by'");
        $has_replied_by = $check->rowCount() > 0;
        
        if ($has_replied_by) {
            $stmt = $pdo->prepare("
                UPDATE feedback 
                SET status = 'replied', admin_reply = ?, replied_by = ?, replied_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reply_message, $admin_id, $feedback_id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE feedback 
                SET status = 'replied', admin_reply = ?, replied_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reply_message, $feedback_id]);
        }
        
        // Log activity
        $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action) VALUES (?, ?)");
        $log->execute([$admin_id, "Replied to feedback ID: $feedback_id"]);
        
        $success_message = "Reply sent successfully";
    }
}

// ===== HANDLE ADD TO TESTIMONIALS =====
if (isset($_GET['add_testimonial'])) {
    $feedback_id = $_GET['add_testimonial'];
    
    $stmt = $pdo->prepare("SELECT * FROM feedback WHERE id = ?");
    $stmt->execute([$feedback_id]);
    $fb = $stmt->fetch();
    
    if ($fb) {
        // Check if already in testimonials
        $check = $pdo->prepare("SELECT id FROM testimonials WHERE feedback_id = ?");
        $check->execute([$feedback_id]);
        
        if (!$check->fetch()) {
            $customer_name = $fb['is_anonymous'] ? 'Anonymous Customer' : $fb['name'];
            
            $stmt = $pdo->prepare("
                INSERT INTO testimonials (feedback_id, customer_name, rating, comment, is_featured, approved_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $feedback_id, 
                $customer_name, 
                $fb['rating'], 
                $fb['message'],
                ($fb['rating'] == 5) ? 1 : 0
            ]);
            
            $success_message = "Feedback added to testimonials!";
        } else {
            $error_message = "This feedback is already a testimonial";
        }
    }
}

// ===== HANDLE REMOVE FROM TESTIMONIALS =====
if (isset($_GET['remove_testimonial'])) {
    $testimonial_id = $_GET['remove_testimonial'];
    
    $stmt = $pdo->prepare("DELETE FROM testimonials WHERE id = ?");
    $stmt->execute([$testimonial_id]);
    
    $success_message = "Testimonial removed";
}

// ===== HANDLE TOGGLE FEATURED =====
if (isset($_GET['toggle_featured'])) {
    $testimonial_id = $_GET['toggle_featured'];
    
    $stmt = $pdo->prepare("UPDATE testimonials SET is_featured = NOT is_featured WHERE id = ?");
    $stmt->execute([$testimonial_id]);
    
    $success_message = "Testimonial featured status updated";
}

// ===== FILTER PARAMETERS =====
$status_filter = $_GET['status'] ?? 'all';
$rating_filter = $_GET['rating'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

// ===== BUILD QUERY =====
$query = "SELECT * FROM feedback WHERE 1=1";
$params = [];

if ($status_filter != 'all') {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

if ($rating_filter != 'all') {
    $query .= " AND rating = ?";
    $params[] = $rating_filter;
}

if ($category_filter != 'all') {
    $query .= " AND category = ?";
    $params[] = $category_filter;
}

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR email LIKE ? OR message LIKE ? OR subject LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

switch ($sort) {
    case 'newest':
        $query .= " ORDER BY created_at DESC";
        break;
    case 'oldest':
        $query .= " ORDER BY created_at ASC";
        break;
    case 'highest':
        $query .= " ORDER BY rating DESC, created_at DESC";
        break;
    case 'lowest':
        $query .= " ORDER BY rating ASC, created_at DESC";
        break;
    default:
        $query .= " ORDER BY created_at DESC";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$feedbacks = $stmt->fetchAll();

// ===== GET STATS =====
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count,
        SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) as replied,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
        SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
        SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
        SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star,
        AVG(rating) as avg_rating
    FROM feedback
")->fetch();

// ===== GET MONTHLY DATA FOR CHART =====
$monthly_data = $pdo->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as total,
        AVG(rating) as avg_rating
    FROM feedback
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
")->fetchAll();

// ===== GET TESTIMONIALS =====
$testimonials = $pdo->query("
    SELECT t.*, f.name as original_name, f.is_anonymous 
    FROM testimonials t
    LEFT JOIN feedback f ON t.feedback_id = f.id
    ORDER BY t.is_featured DESC, t.id DESC
    LIMIT 20
")->fetchAll();

// ===== GET FEEDBACK FOR VIEWING =====
$view_feedback = null;
if (isset($_GET['view'])) {
    // Simple query without JOIN to avoid errors
    $stmt = $pdo->prepare("SELECT * FROM feedback WHERE id = ?");
    $stmt->execute([$_GET['view']]);
    $view_feedback = $stmt->fetch();
    
    // Mark as read when viewed
    if ($view_feedback && $view_feedback['status'] == 'new') {
        $stmt = $pdo->prepare("UPDATE feedback SET status = 'read' WHERE id = ?");
        $stmt->execute([$_GET['view']]);
        $view_feedback['status'] = 'read';
    }
}

// Categories for filter
$categories = [
    'general' => 'General Feedback',
    'food' => 'Food Quality',
    'service' => 'Service',
    'delivery' => 'Delivery',
    'suggestion' => 'Suggestion',
    'complaint' => 'Complaint'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback Management | Yadang's Time Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --star-color: #FFD700;
            --heart-color: #ff6b6b;
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a2c22 0%, #2d4638 100%);
            color: var(--text-light);
            min-height: 100vh;
            line-height: 1.6;
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
        
        /* ===== HEADER - SAMA MACAM REPORTS PAGE ===== */
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
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 15px 25px rgba(250, 161, 143, 0.15);
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
        .stat-icon.replied { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        .stat-icon.star { background: rgba(255, 215, 0, 0.2); color: var(--star-color); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
        }
        
        .stat-small {
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.6);
            margin-top: 0.3rem;
        }
        
        /* ===== CHART SECTION ===== */
        .chart-container {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .chart-title {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--accent-color);
        }
        
        .chart-title i {
            color: var(--accent-color);
        }
        
        .chart-wrapper {
            height: 200px;
            position: relative;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        
        .filter-select,
        .filter-input {
            width: 100%;
            padding: 0.8rem;
            background: rgba(26, 44, 34, 0.7);
            border: 2px solid rgba(250, 161, 143, 0.2);
            border-radius: 10px;
            color: var(--text-light);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(250, 161, 143, 0.2);
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
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .reset-btn {
            background: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* ===== FEEDBACK CARDS GRID ===== */
        .section-title {
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 15px;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
        }
        
        .section-title i {
            color: var(--accent-color);
        }
        
        .feedback-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        
        .feedback-card {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: fit-content;
            animation: fadeInUp 0.6s ease;
        }
        
        .feedback-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 15px 25px rgba(250, 161, 143, 0.15);
        }
        
        .feedback-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), var(--light-accent));
        }
        
        .feedback-card.unread {
            border-left: 4px solid var(--warning-color);
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
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .card-avatar {
            width: 50px;
            height: 50px;
            background: var(--accent-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--dark-text);
            font-weight: bold;
            flex-shrink: 0;
        }
        
        .card-user-info {
            flex: 1;
            min-width: 0;
        }
        
        .card-user-name {
            font-weight: 600;
            font-size: 1.1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .card-user-email {
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.6);
        }
        
        .card-rating {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            color: var(--star-color);
            margin-bottom: 0.8rem;
        }
        
        .card-category {
            display: inline-block;
            padding: 0.3rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            margin-bottom: 0.8rem;
        }
        
        .category-general { background: rgba(52,152,219,0.2); color: var(--info-color); }
        .category-food { background: rgba(46,204,113,0.2); color: var(--success-color); }
        .category-service { background: rgba(155,89,182,0.2); color: #9b59b6; }
        .category-delivery { background: rgba(241,196,15,0.2); color: var(--warning-color); }
        .category-suggestion { background: rgba(52,152,219,0.2); color: var(--info-color); }
        .category-complaint { background: rgba(231,76,60,0.2); color: var(--danger-color); }
        
        .card-subject {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .card-message {
            color: rgba(232, 223, 208, 0.8);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1rem;
            max-height: 100px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }
        
        .card-message::-webkit-scrollbar {
            width: 4px;
        }
        
        .card-message::-webkit-scrollbar-track {
            background: rgba(232,223,208,0.1);
        }
        
        .card-message::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 4px;
        }
        
        .card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: rgba(232, 223, 208, 0.6);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(250, 161, 143, 0.1);
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-new {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
        }
        
        .status-read {
            background: rgba(52, 152, 219, 0.2);
            color: var(--info-color);
        }
        
        .status-replied {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }
        
        .card-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
        
        .btn-icon.view {
            background: rgba(52, 152, 219, 0.2);
            color: var(--info-color);
            border: 1px solid var(--info-color);
        }
        
        .btn-icon.view:hover {
            background: var(--info-color);
            color: white;
        }
        
        .btn-icon.read {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }
        
        .btn-icon.read:hover {
            background: var(--success-color);
            color: white;
        }
        
        .btn-icon.unread {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }
        
        .btn-icon.unread:hover {
            background: var(--warning-color);
            color: white;
        }
        
        .btn-icon.star {
            background: rgba(255, 215, 0, 0.2);
            color: var(--star-color);
            border: 1px solid var(--star-color);
        }
        
        .btn-icon.star:hover {
            background: var(--star-color);
            color: var(--dark-text);
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
        
        /* ===== TESTIMONIALS SECTION - HORIZONTAL SCROLL ===== */
        .testimonials-section {
            margin-bottom: 3rem;
            position: relative;
        }
        
        .testimonials-section:hover .scroll-arrow {
            opacity: 1;
        }
        
        .testimonials-scroll-container {
            overflow-x: auto;
            overflow-y: hidden;
            scroll-behavior: smooth;
            padding: 0.5rem 0 1.5rem 0;
            margin: 0 -0.5rem;
            scrollbar-width: thin;
            scrollbar-color: var(--accent-color) var(--container-bg);
        }
        
        .testimonials-scroll-container::-webkit-scrollbar {
            height: 8px;
        }
        
        .testimonials-scroll-container::-webkit-scrollbar-track {
            background: var(--container-bg);
            border-radius: 10px;
        }
        
        .testimonials-scroll-container::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 10px;
        }
        
        .testimonials-scroll-container::-webkit-scrollbar-thumb:hover {
            background: var(--light-accent);
        }
        
        .testimonials-grid {
            display: flex;
            flex-wrap: nowrap;
            gap: 1.5rem;
            padding: 0 0.5rem;
        }
        
        .testimonial-card {
            flex: 0 0 300px;
            height: 320px;
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            transition: all 0.3s ease;
            position: relative;
            box-shadow: 0 10px 30px var(--shadow-color);
            display: flex;
            flex-direction: column;
        }
        
        .testimonial-card:hover {
            transform: translateY(-5px) scale(1.02);
            border-color: var(--accent-color);
            box-shadow: 0 20px 30px rgba(250, 161, 143, 0.2);
        }
        
        .testimonial-card.featured {
            border: 2px solid var(--star-color);
            position: relative;
            overflow: hidden;
        }
        
        .testimonial-card.featured::before {
            content: '⭐ FEATURED';
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--star-color);
            color: var(--dark-text);
            padding: 0.2rem 0.8rem;
            border-radius: 15px;
            font-size: 0.7rem;
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
            flex-shrink: 0;
        }
        
        .testimonial-info {
            flex: 1;
            min-width: 0;
        }
        
        .testimonial-name {
            font-weight: 600;
            font-size: 1.1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .testimonial-rating {
            color: var(--star-color);
            font-size: 0.9rem;
        }
        
        .testimonial-comment {
            color: rgba(232, 223, 208, 0.9);
            line-height: 1.6;
            margin-bottom: 1rem;
            font-style: italic;
            flex: 1;
            overflow-y: auto;
            padding-right: 0.5rem;
        }
        
        .testimonial-comment::-webkit-scrollbar {
            width: 4px;
        }
        
        .testimonial-comment::-webkit-scrollbar-track {
            background: rgba(232,223,208,0.1);
        }
        
        .testimonial-comment::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 4px;
        }
        
        .testimonial-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: rgba(232, 223, 208, 0.6);
            font-size: 0.9rem;
            padding-top: 0.5rem;
            border-top: 1px solid rgba(250, 161, 143, 0.1);
        }
        
        .testimonial-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .scroll-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            background: var(--accent-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-text);
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 10;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }
        
        .scroll-arrow.left {
            left: -20px;
        }
        
        .scroll-arrow.right {
            right: -20px;
        }
        
        .scroll-arrow:hover {
            transform: translateY(-50%) scale(1.1);
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
            display: <?php echo $view_feedback ? 'flex' : 'none'; ?>;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }
        
        .modal-content {
            background: var(--container-bg);
            border-radius: 15px;
            max-width: 700px;
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
        
        .feedback-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 10px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            color: var(--accent-color);
            font-size: 0.8rem;
            margin-bottom: 0.3rem;
        }
        
        .detail-value {
            font-weight: 600;
        }
        
        .message-box {
            background: rgba(26, 44, 34, 0.5);
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--accent-color);
        }
        
        .message-label {
            color: var(--accent-color);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .reply-box {
            background: rgba(26, 44, 34, 0.5);
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--success-color);
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
            min-height: 100px;
            margin-top: 0.5rem;
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
        
        .modal-btn-danger {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
        }
        
        .modal-btn-danger:hover {
            background: var(--danger-color);
            color: white;
        }
        
        /* ===== FOOTER ===== */
        .main-footer {
            background: linear-gradient(135deg, #1a2c22, #0f1913);
            color: var(--text-light);
            padding: 2rem;
            margin-top: 4rem;
            text-align: center;
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
            }
            
            .logo-section {
                flex-direction: column;
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
            
            .feedback-grid {
                grid-template-columns: 1fr;
            }
            
            .testimonial-card {
                flex: 0 0 280px;
                height: 300px;
            }
            
            .feedback-detail-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-actions {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .logo-circle {
                width: 40px;
                height: 40px;
            }
            
            .logo-circle img {
                width: 25px;
                height: 25px;
            }
            
            .brand-text h1 {
                font-size: 1.2rem;
            }
            
            .admin-avatar {
                width: 35px;
                height: 35px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
    
    <!-- Header - SAMA MACAM REPORTS PAGE -->
    <header class="main-header">
        <div class="logo-section">
            <div class="logo-circle">
                <img src="../images/yadangs_logo.png" alt="Yadang's Time">
            </div>
            <div class="brand-text">
                <h1>YADANG'S TIME</h1>
                <p>Feedback Management</p>
            </div>
        </div>
        
        <div class="admin-info">
            <div class="admin-badge">
                <div class="admin-avatar">
                    <?php echo strtoupper(substr($admin_name, 0, 1)); ?>
                </div>
                <div>
                    <div style="font-weight: 600;"><?php echo htmlspecialchars($admin_name); ?></div>
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
                <i class="fas fa-comment-dots"></i>
                Customer Feedback
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
                    <div class="stat-title">Total Feedback</div>
                    <div class="stat-icon total">
                        <i class="fas fa-comment"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-small">All time</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Unread</div>
                    <div class="stat-icon unread">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['unread'] ?? 0; ?></div>
                <div class="stat-small">Need attention</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Replied</div>
                    <div class="stat-icon replied">
                        <i class="fas fa-reply"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['replied'] ?? 0; ?></div>
                <div class="stat-small"><?php echo round(($stats['replied'] / max($stats['total'], 1) * 100), 1); ?>% response rate</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Avg Rating</div>
                    <div class="stat-icon star">
                        <i class="fas fa-star"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['avg_rating'] ?? 0, 1); ?></div>
                <div class="stat-small"><?php echo $stats['five_star'] ?? 0; ?> five-star ⭐</div>
            </div>
        </div>
        
        <!-- Rating Breakdown -->
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 2rem;">
            <span class="status-badge status-read">5 ⭐: <?php echo $stats['five_star'] ?? 0; ?></span>
            <span class="status-badge status-read">4 ⭐: <?php echo $stats['four_star'] ?? 0; ?></span>
            <span class="status-badge status-read">3 ⭐: <?php echo $stats['three_star'] ?? 0; ?></span>
            <span class="status-badge status-read">2 ⭐: <?php echo $stats['two_star'] ?? 0; ?></span>
            <span class="status-badge status-read">1 ⭐: <?php echo $stats['one_star'] ?? 0; ?></span>
        </div>
        
        <!-- Chart Section -->
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <i class="fas fa-chart-line"></i> Feedback Trends (Last 6 Months)
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="feedbackChart"></canvas>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <h3 class="filter-title">
                <i class="fas fa-filter"></i>
                Filter Feedback
            </h3>
            
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-select">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Feedback</option>
                            <option value="new" <?php echo $status_filter == 'new' ? 'selected' : ''; ?>>🟡 New (Unread)</option>
                            <option value="read" <?php echo $status_filter == 'read' ? 'selected' : ''; ?>>🔵 Read</option>
                            <option value="replied" <?php echo $status_filter == 'replied' ? 'selected' : ''; ?>>🟢 Replied</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Rating</label>
                        <select name="rating" class="filter-select">
                            <option value="all" <?php echo $rating_filter == 'all' ? 'selected' : ''; ?>>All Ratings</option>
                            <option value="5" <?php echo $rating_filter == '5' ? 'selected' : ''; ?>>5 Stars ⭐⭐⭐⭐⭐</option>
                            <option value="4" <?php echo $rating_filter == '4' ? 'selected' : ''; ?>>4 Stars ⭐⭐⭐⭐</option>
                            <option value="3" <?php echo $rating_filter == '3' ? 'selected' : ''; ?>>3 Stars ⭐⭐⭐</option>
                            <option value="2" <?php echo $rating_filter == '2' ? 'selected' : ''; ?>>2 Stars ⭐⭐</option>
                            <option value="1" <?php echo $rating_filter == '1' ? 'selected' : ''; ?>>1 Star ⭐</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Category</label>
                        <select name="category" class="filter-select">
                            <option value="all" <?php echo $category_filter == 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $category_filter == $key ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Sort</label>
                        <select name="sort" class="filter-select">
                            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                            <option value="highest" <?php echo $sort == 'highest' ? 'selected' : ''; ?>>Highest Rated</option>
                            <option value="lowest" <?php echo $sort == 'lowest' ? 'selected' : ''; ?>>Lowest Rated</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Search</label>
                        <input type="text" name="search" class="filter-input" 
                               placeholder="Name, email, message..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="feedback.php" class="reset-btn">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- FEEDBACK CARDS GRID -->
        <h2 class="section-title">
            <i class="fas fa-list"></i>
            Customer Feedback (<?php echo count($feedbacks); ?>)
        </h2>
        
        <div class="feedback-grid">
            <?php if (empty($feedbacks)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 4rem; background: var(--container-bg); border-radius: 15px;">
                    <i class="fas fa-comment-slash" style="font-size: 4rem; color: rgba(250,161,143,0.3); margin-bottom: 1rem;"></i>
                    <h3>No feedback found</h3>
                    <p style="color: rgba(232,223,208,0.6);">Try adjusting your filters</p>
                </div>
            <?php else: ?>
                <?php foreach ($feedbacks as $fb): ?>
                    <div class="feedback-card <?php echo ($fb['status'] ?? '') == 'new' ? 'unread' : ''; ?>">
                        <div class="card-header">
                            <div class="card-avatar">
                                <?php 
                                $initial = $fb['is_anonymous'] ? 'A' : ($fb['name'] ? strtoupper(substr($fb['name'], 0, 1)) : '?');
                                echo $initial; 
                                ?>
                            </div>
                            <div class="card-user-info">
                                <div class="card-user-name">
                                    <?php echo $fb['is_anonymous'] ? 'Anonymous' : htmlspecialchars($fb['name'] ?: 'Unknown'); ?>
                                </div>
                                <div class="card-user-email"><?php echo htmlspecialchars($fb['email'] ?: 'No email'); ?></div>
                            </div>
                        </div>
                        
                        <div class="card-rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star" style="color: <?php echo $i <= ($fb['rating'] ?? 0) ? 'var(--star-color)' : 'rgba(255,215,0,0.2)'; ?>"></i>
                            <?php endfor; ?>
                            <span style="margin-left: 0.3rem; font-size: 0.9rem;">(<?php echo $fb['rating'] ?? 0; ?>)</span>
                        </div>
                        
                        <div class="card-category category-<?php echo $fb['category'] ?? 'general'; ?>">
                            <i class="fas fa-tag"></i> <?php echo $categories[$fb['category']] ?? 'General'; ?>
                        </div>
                        
                        <?php if (!empty($fb['subject'])): ?>
                            <div class="card-subject">
                                <i class="fas fa-heading" style="color: var(--accent-color); font-size: 0.8rem;"></i>
                                <?php echo htmlspecialchars($fb['subject']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="card-message">
                            <?php echo nl2br(htmlspecialchars($fb['message'] ?? '')); ?>
                        </div>
                        
                        <div class="card-meta">
                            <span><i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($fb['created_at'] ?? 'now')); ?></span>
                            <span class="status-badge status-<?php echo $fb['status'] ?? 'new'; ?>">
                                <?php echo ucfirst($fb['status'] ?? 'new'); ?>
                            </span>
                        </div>
                        
                        <div class="card-actions">
                            <a href="?view=<?php echo $fb['id']; ?>" class="btn-icon view" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            
                            <?php if (($fb['status'] ?? '') == 'new'): ?>
                                <a href="?mark_read=<?php echo $fb['id']; ?>" class="btn-icon read" title="Mark as Read">
                                    <i class="fas fa-check"></i>
                                </a>
                            <?php elseif (($fb['status'] ?? '') == 'read'): ?>
                                <a href="?mark_unread=<?php echo $fb['id']; ?>" class="btn-icon unread" title="Mark as Unread">
                                    <i class="fas fa-envelope"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php if (($fb['rating'] ?? 0) >= 4 && !$fb['is_anonymous']): ?>
                                <a href="?add_testimonial=<?php echo $fb['id']; ?>" class="btn-icon star" title="Add to Testimonials">
                                    <i class="fas fa-star"></i>
                                </a>
                            <?php endif; ?>
                            
                            <a href="?delete=<?php echo $fb['id']; ?>" class="btn-icon delete" title="Delete" 
                               onclick="return confirm('Delete this feedback? This action cannot be undone.')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- TESTIMONIALS SECTION - HORIZONTAL SCROLL -->
        <div class="testimonials-section">
            <h2 class="section-title">
                <i class="fas fa-star" style="color: var(--star-color);"></i>
                Featured Testimonials
                <span style="font-size: 1rem; color: rgba(232,223,208,0.6);">(<?php echo count($testimonials); ?> items)</span>
            </h2>
            
            <?php if (empty($testimonials)): ?>
                <div style="text-align: center; padding: 4rem; background: var(--container-bg); border-radius: 15px;">
                    <i class="fas fa-star" style="font-size: 4rem; color: rgba(255,215,0,0.3); margin-bottom: 1rem;"></i>
                    <h3>No testimonials yet</h3>
                    <p style="color: rgba(232,223,208,0.6);">Click the ⭐ icon on 4-5 star feedback to add testimonials</p>
                </div>
            <?php else: ?>
                <!-- Scroll Arrows -->
                <div class="scroll-arrow left" onclick="scrollTestimonials(-300)">◀</div>
                <div class="scroll-arrow right" onclick="scrollTestimonials(300)">▶</div>
                
                <div class="testimonials-scroll-container" id="testimonialScroll">
                    <div class="testimonials-grid">
                        <?php foreach ($testimonials as $t): ?>
                            <div class="testimonial-card <?php echo $t['is_featured'] ? 'featured' : ''; ?>">
                                <div class="testimonial-header">
                                    <div class="testimonial-avatar">
                                        <?php echo strtoupper(substr($t['customer_name'] ?? 'C', 0, 1)); ?>
                                    </div>
                                    <div class="testimonial-info">
                                        <div class="testimonial-name">
                                            <?php echo htmlspecialchars($t['customer_name'] ?? 'Customer'); ?>
                                        </div>
                                        <div class="testimonial-rating">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star" style="color: <?php echo $i <= ($t['rating'] ?? 5) ? 'var(--star-color)' : 'rgba(255,215,0,0.2)'; ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="testimonial-comment">
                                    "<?php echo htmlspecialchars($t['comment'] ?? ''); ?>"
                                </div>
                                
                                <div class="testimonial-footer">
                                    <span>
                                        <i class="far fa-clock"></i> 
                                        <?php echo date('d M Y', strtotime($t['approved_at'] ?? 'now')); ?>
                                    </span>
                                    <div class="testimonial-actions">
                                        <a href="?toggle_featured=<?php echo $t['id']; ?>" class="btn-icon star" title="Toggle Featured" style="width: 30px; height: 30px;">
                                            <i class="fas fa-star"></i>
                                        </a>
                                        <a href="?remove_testimonial=<?php echo $t['id']; ?>" class="btn-icon delete" title="Remove" style="width: 30px; height: 30px;"
                                           onclick="return confirm('Remove from testimonials?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- View Feedback Modal -->
    <?php if ($view_feedback): ?>
    <div class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Feedback Details</h2>
                <a href="feedback.php" class="modal-close">&times;</a>
            </div>
            
            <!-- Customer Info -->
            <div class="feedback-detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Customer</span>
                    <span class="detail-value">
                        <?php echo $view_feedback['is_anonymous'] ? 'Anonymous' : htmlspecialchars($view_feedback['name'] ?: 'Unknown'); ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Email</span>
                    <span class="detail-value"><?php echo htmlspecialchars($view_feedback['email'] ?: 'Not provided'); ?></span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Phone</span>
                    <span class="detail-value"><?php echo htmlspecialchars($view_feedback['phone'] ?: 'Not provided'); ?></span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Date</span>
                    <span class="detail-value"><?php echo date('d/m/Y H:i', strtotime($view_feedback['created_at'] ?? 'now')); ?></span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Rating</span>
                    <span class="detail-value">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star" style="color: <?php echo $i <= ($view_feedback['rating'] ?? 0) ? 'var(--star-color)' : 'rgba(255,215,0,0.2)'; ?>"></i>
                        <?php endfor; ?>
                    </span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Category</span>
                    <span class="detail-value"><?php echo $categories[$view_feedback['category']] ?? $view_feedback['category'] ?? 'General'; ?></span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Subject</span>
                    <span class="detail-value"><?php echo htmlspecialchars($view_feedback['subject'] ?: 'No subject'); ?></span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">Status</span>
                    <span class="detail-value">
                        <span class="status-badge status-<?php echo $view_feedback['status'] ?? 'new'; ?>">
                            <?php echo ucfirst($view_feedback['status'] ?? 'new'); ?>
                        </span>
                    </span>
                </div>
            </div>
            
            <!-- Message -->
            <div class="message-box">
                <div class="message-label">
                    <i class="fas fa-quote-left"></i>
                    Customer Message
                </div>
                <p style="line-height: 1.8;"><?php echo nl2br(htmlspecialchars($view_feedback['message'] ?? '')); ?></p>
            </div>
            
            <!-- Reply Section -->
            <?php if (($view_feedback['status'] ?? '') == 'replied' && !empty($view_feedback['admin_reply'])): ?>
                <div class="reply-box">
                    <div class="message-label" style="color: var(--success-color);">
                        <i class="fas fa-reply"></i>
                        Your Reply (<?php echo $view_feedback['replied_at'] ? date('d/m/Y H:i', strtotime($view_feedback['replied_at'])) : 'Just now'; ?>)
                    </div>
                    <p style="line-height: 1.8;"><?php echo nl2br(htmlspecialchars($view_feedback['admin_reply'])); ?></p>
                </div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="feedback_id" value="<?php echo $view_feedback['id']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Reply to Customer</label>
                        <textarea name="reply_message" class="reply-textarea" 
                                  placeholder="Type your reply here..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <a href="feedback.php" class="modal-btn modal-btn-secondary">Cancel</a>
                        <button type="submit" name="reply_feedback" class="modal-btn modal-btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Reply
                        </button>
                        <a href="?delete=<?php echo $view_feedback['id']; ?>" class="modal-btn modal-btn-danger"
                           onclick="return confirm('Delete this feedback?')">
                            <i class="fas fa-trash"></i> Delete
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time Admin Panel</p>
            <p style="margin-top: 0.5rem;">
                <i class="fas fa-heart" style="color: var(--accent-color);"></i>
                Feedback Management • <?php echo $stats['unread'] ?? 0; ?> unread • Avg Rating <?php echo number_format($stats['avg_rating'] ?? 0, 1); ?> ⭐
            </p>
        </div>
    </footer>
    
    <script>
        // ===== CHART INITIALIZATION =====
        const ctx = document.getElementById('feedbackChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php 
                $labels = array_map(function($item) {
                    return date('M Y', strtotime($item['month'] . '-01'));
                }, array_reverse($monthly_data));
                echo json_encode($labels); 
                ?>,
                datasets: [{
                    label: 'Feedback Volume',
                    data: <?php echo json_encode(array_column(array_reverse($monthly_data), 'total')); ?>,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Average Rating',
                    data: <?php echo json_encode(array_column(array_reverse($monthly_data), 'avg_rating')); ?>,
                    borderColor: '#FFD700',
                    backgroundColor: 'rgba(255, 215, 0, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#E8DFD0' }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(232,223,208,0.1)' },
                        ticks: { color: '#E8DFD0' }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: { color: 'rgba(232,223,208,0.1)' },
                        ticks: { color: '#E8DFD0' }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        min: 0,
                        max: 5,
                        grid: { drawOnChartArea: false },
                        ticks: { color: '#E8DFD0' }
                    }
                }
            }
        });
        
        // ===== SCROLL TESTIMONIALS =====
        function scrollTestimonials(amount) {
            const container = document.getElementById('testimonialScroll');
            if (container) {
                container.scrollBy({
                    left: amount,
                    behavior: 'smooth'
                });
            }
        }
        
        // ===== AUTO-HIDE ALERTS =====
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // ===== CLOSE MODAL WITH ESCAPE =====
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.querySelector('.modal-overlay')) {
                window.location.href = 'feedback.php';
            }
        });
    </script>
</body>
</html>
