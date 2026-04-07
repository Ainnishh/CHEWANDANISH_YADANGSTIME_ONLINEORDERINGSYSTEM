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

// ===== HANDLE STATUS UPDATE =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $order_id = $_POST['order_id'];
    $new_status = $_POST['status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
        if ($stmt->execute([$new_status, $admin_id, $order_id])) {
            // Log activity
            $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
            $log->execute([$admin_id, 'update_order', "Updated order #$order_id to $new_status", $_SERVER['REMOTE_ADDR']]);
            
            $success_message = "Order status updated successfully!";
        }
    } catch (PDOException $e) {
        $error_message = "Error updating order: " . $e->getMessage();
    }
}

// ===== HANDLE VIEW ORDER DETAILS =====
$order_details = null;
$order_items = [];
if (isset($_GET['view'])) {
    $order_id = $_GET['view'];
    
    // Get order details
    $stmt = $pdo->prepare("
        SELECT o.*, 
               CASE WHEN o.processed_by IS NOT NULL THEN 'Admin' ELSE '-' END as processed_by_name
        FROM orders o 
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order_details = $stmt->fetch();
    
    // Get order items
    if ($order_details) {
        $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order_items = $stmt->fetchAll();
    }
}

// ===== FILTER PARAMETERS =====
$status_filter = $_GET['status'] ?? '';
$payment_filter = $_GET['payment'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// ===== BUILD QUERY =====
$query = "
    SELECT o.*, 
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
    FROM orders o 
    WHERE DATE(o.order_date) BETWEEN ? AND ?
";
$params = [$date_from, $date_to];

if (!empty($status_filter)) {
    $query .= " AND o.status = ?";
    $params[] = $status_filter;
}

if (!empty($payment_filter)) {
    $query .= " AND o.payment_method = ?";
    $params[] = $payment_filter;
}

if (!empty($search)) {
    $query .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY o.order_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// ===== GET STATS =====
$stats = [];
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COALESCE(AVG(total_amount), 0) as avg_order_value
    FROM orders 
    WHERE DATE(order_date) BETWEEN ? AND ?
");
$stmt->execute([$date_from, $date_to]);
$stats = $stmt->fetch();

// Status options
$status_options = [
    'pending' => ['label' => 'Pending', 'color' => '#f39c12', 'icon' => '⏳'],
    'processing' => ['label' => 'Processing', 'color' => '#3498db', 'icon' => '⚙️'],
    'completed' => ['label' => 'Completed', 'color' => '#2ecc71', 'icon' => '✅'],
    'cancelled' => ['label' => 'Cancelled', 'color' => '#e74c3c', 'icon' => '❌']
];

// Payment methods
$payment_methods = [
    'cash' => '💵 Cash',
    'card' => '💳 Card',
    'tng' => '📱 Touch n Go'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders | Yadang's Time Admin</title>
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
        
        .stat-label {
            color: rgba(232, 223, 208, 0.7);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--accent-color);
        }
        
        .stat-small {
            font-size: 0.9rem;
            color: rgba(232, 223, 208, 0.6);
            margin-top: 0.3rem;
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
        
        .filter-input,
        .filter-select {
            width: 100%;
            padding: 0.8rem;
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid rgba(250, 161, 143, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
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
        
        /* ===== ORDERS TABLE ===== */
        .table-card {
            background: var(--container-bg);
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            overflow-x: auto;
        }
        
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        
        .orders-table th {
            text-align: left;
            padding: 1rem 0.5rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
            color: var(--accent-color);
            font-weight: 600;
        }
        
        .orders-table td {
            padding: 1rem 0.5rem;
            border-bottom: 1px solid rgba(232, 223, 208, 0.1);
            vertical-align: middle;
        }
        
        .orders-table tr:hover td {
            background: rgba(250, 161, 143, 0.05);
        }
        
        .order-number {
            font-weight: 600;
            color: var(--accent-color);
        }
        
        .customer-info {
            display: flex;
            flex-direction: column;
        }
        
        .customer-name {
            font-weight: 600;
        }
        
        .customer-phone {
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.6);
        }
        
        .status-badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-pending {
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }
        
        .status-processing {
            background: rgba(52, 152, 219, 0.2);
            color: var(--info-color);
            border: 1px solid var(--info-color);
        }
        
        .status-completed {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }
        
        .status-cancelled {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }
        
        .payment-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            background: rgba(255, 255, 255, 0.1);
            display: inline-block;
        }
        
        .amount {
            font-weight: 600;
            color: var(--success-color);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .status-select {
            padding: 0.5rem;
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid var(--accent-color);
            border-radius: 5px;
            color: var(--text-light);
            font-size: 0.85rem;
            cursor: pointer;
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
        
        /* ===== ORDER DETAILS MODAL ===== */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 25, 19, 0.95);
            backdrop-filter: blur(5px);
            display: <?php echo isset($_GET['view']) ? 'flex' : 'none'; ?>;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }
        
        .modal-content {
            background: var(--container-bg);
            border-radius: 20px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 2rem;
            border: 2px solid var(--accent-color);
            animation: modalSlideIn 0.3s ease;
            position: relative;
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
            line-height: 1;
        }
        
        .modal-close:hover {
            color: var(--accent-color);
        }
        
        .order-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            color: var(--accent-color);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }
        
        .info-value {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }
        
        .items-table th {
            text-align: left;
            padding: 0.8rem 0.5rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
            color: var(--accent-color);
        }
        
        .items-table td {
            padding: 0.8rem 0.5rem;
            border-bottom: 1px solid rgba(232, 223, 208, 0.1);
        }
        
        .customization-note {
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.6);
            font-style: italic;
        }
        
        .total-section {
            text-align: right;
            padding: 1.5rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 10px;
            font-size: 1.2rem;
        }
        
        .total-amount {
            font-size: 1.8rem;
            color: var(--success-color);
            font-weight: bold;
            margin-left: 1rem;
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
            text-decoration: none;
            text-align: center;
        }
        
        .modal-btn-primary {
            background: var(--accent-color);
            color: var(--dark-text);
        }
        
        .modal-btn-secondary {
            background: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
        }
        
        .modal-btn-primary:hover,
        .modal-btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
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
            
            .order-info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .modal-actions {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                padding: 1.5rem;
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
                <p>Orders Management</p>
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
                <i class="fas fa-shopping-cart"></i>
                Manage Orders
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
                <div class="stat-label">Total Orders</div>
                <div class="stat-value"><?php echo $stats['total_orders'] ?? 0; ?></div>
                <div class="stat-small">Selected period</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Pending</div>
                <div class="stat-value" style="color: var(--warning-color);"><?php echo $stats['pending_count'] ?? 0; ?></div>
                <div class="stat-small">Need attention</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Processing</div>
                <div class="stat-value" style="color: var(--info-color);"><?php echo $stats['processing_count'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Completed</div>
                <div class="stat-value" style="color: var(--success-color);"><?php echo $stats['completed_count'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value">RM <?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></div>
                <div class="stat-small">Avg: RM <?php echo number_format($stats['avg_order_value'] ?? 0, 2); ?></div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <h3 class="filter-title">
                <i class="fas fa-filter"></i>
                Filter Orders
            </h3>
            
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-select">
                            <option value="">All Status</option>
                            <?php foreach ($status_options as $key => $status): ?>
                                <option value="<?php echo $key; ?>" <?php echo $status_filter == $key ? 'selected' : ''; ?>>
                                    <?php echo $status['icon'] . ' ' . $status['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Payment Method</label>
                        <select name="payment" class="filter-select">
                            <option value="">All Payments</option>
                            <?php foreach ($payment_methods as $key => $method): ?>
                                <option value="<?php echo $key; ?>" <?php echo $payment_filter == $key ? 'selected' : ''; ?>>
                                    <?php echo $method; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date From</label>
                        <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date To</label>
                        <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Search</label>
                        <input type="text" name="search" class="filter-input" 
                               placeholder="Order #, customer name, phone"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="orders.php" class="reset-btn">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Orders Table -->
        <div class="table-card">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date & Time</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 3rem;">
                                <i class="fas fa-shopping-cart" style="font-size: 3rem; color: rgba(250,161,143,0.3);"></i>
                                <p style="margin-top: 1rem;">No orders found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <span class="order-number"><?php echo $order['order_number']; ?></span>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y H:i', strtotime($order['order_date'])); ?>
                                </td>
                                <td>
                                    <div class="customer-info">
                                        <span class="customer-name"><?php echo htmlspecialchars($order['customer_name'] ?? 'Guest'); ?></span>
                                        <span class="customer-phone"><?php echo $order['customer_phone'] ?? ''; ?></span>
                                    </div>
                                </td>
                                <td><?php echo $order['item_count']; ?> items</td>
                                <td>
                                    <span class="amount">RM <?php echo number_format($order['total_amount'], 2); ?></span>
                                </td>
                                <td>
                                    <span class="payment-badge">
                                        <?php echo $payment_methods[$order['payment_method']] ?? $order['payment_method']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $order['status']; ?>">
                                        <?php echo $status_options[$order['status']]['icon'] ?? ''; ?>
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <select name="status" class="status-select" onchange="this.form.submit()">
                                                <?php foreach ($status_options as $key => $status): ?>
                                                    <option value="<?php echo $key; ?>" <?php echo $order['status'] == $key ? 'selected' : ''; ?>>
                                                        <?php echo $status['icon'] . ' ' . $status['label']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="update_status" value="1">
                                        </form>
                                        
                                        <a href="?view=<?php echo $order['id']; ?><?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : ''; ?>" class="btn-icon view" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
    
    <!-- Order Details Modal -->
    <?php if ($order_details): ?>
    <div class="modal-overlay" id="orderModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Order Details #<?php echo $order_details['order_number']; ?></h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <!-- Order Info -->
            <div class="order-info-grid">
                <div class="info-item">
                    <span class="info-label">Customer</span>
                    <span class="info-value"><?php echo htmlspecialchars($order_details['customer_name'] ?? 'Guest'); ?></span>
                    <small style="color: rgba(232,223,208,0.6);"><?php echo $order_details['customer_phone'] ?? ''; ?></small>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Order Date</span>
                    <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($order_details['order_date'])); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Payment Method</span>
                    <span class="info-value"><?php echo $payment_methods[$order_details['payment_method']] ?? $order_details['payment_method']; ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="status-badge status-<?php echo $order_details['status']; ?>" style="display: inline-block;">
                            <?php echo ucfirst($order_details['status']); ?>
                        </span>
                    </span>
                </div>
            </div>
            
            <!-- Delivery Info -->
            <?php if (!empty($order_details['delivery_address'])): ?>
            <div style="background: rgba(26,44,34,0.5); padding: 1.5rem; border-radius: 10px; margin-bottom: 2rem;">
                <strong style="color: var(--accent-color); display: block; margin-bottom: 0.5rem;">📍 Delivery Address:</strong>
                <p><?php echo nl2br(htmlspecialchars($order_details['delivery_address'])); ?></p>
                
                <?php if (!empty($order_details['special_instructions'])): ?>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(250,161,143,0.2);">
                        <strong style="color: var(--accent-color); display: block; margin-bottom: 0.5rem;">📝 Special Instructions:</strong>
                        <p><?php echo nl2br(htmlspecialchars($order_details['special_instructions'])); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Order Items -->
            <h3 style="margin-bottom: 1rem; color: var(--accent-color);">🛒 Order Items</h3>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order_items as $item): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                <?php if (!empty($item['customizations'])): ?>
                                    <div class="customization-note">
                                        <?php 
                                        $custom = json_decode($item['customizations'], true);
                                        if ($custom) {
                                            $notes = [];
                                            if (!empty($custom['sweetness'])) $notes[] = "Sugar: " . $custom['sweetness'];
                                            if (!empty($custom['milk']) && $custom['milk'] != 'regular') $notes[] = "Milk: " . ucfirst($custom['milk']);
                                            if (!empty($custom['syrup']) && $custom['syrup'] != 'none') $notes[] = "Syrup: " . ucfirst($custom['syrup']);
                                            echo implode(' • ', $notes);
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td>RM <?php echo number_format($item['price'], 2); ?></td>
                            <td>RM <?php echo number_format($item['subtotal'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Total Summary -->
            <div class="total-section">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span>Subtotal:</span>
                    <span>RM <?php echo number_format($order_details['subtotal'] ?? 0, 2); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span>Delivery Fee:</span>
                    <span>RM <?php echo number_format($order_details['delivery_fee'] ?? 0, 2); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span>Tax (6%):</span>
                    <span>RM <?php echo number_format($order_details['tax_amount'] ?? 0, 2); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 1.5rem; margin-top: 1rem; padding-top: 1rem; border-top: 2px solid rgba(250,161,143,0.3);">
                    <span>Total:</span>
                    <span class="total-amount">RM <?php echo number_format($order_details['total_amount'], 2); ?></span>
                </div>
            </div>
            
            <!-- Status Update -->
            <div style="margin-top: 2rem; padding: 1.5rem; background: rgba(26,44,34,0.5); border-radius: 10px;">
                <h3 style="margin-bottom: 1rem; color: var(--accent-color);">⚡ Update Status</h3>
                
                <form method="POST" action="orders.php?<?php echo http_build_query($_GET); ?>">
                    <input type="hidden" name="order_id" value="<?php echo $order_details['id']; ?>">
                    
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <select name="status" class="filter-input" style="flex: 1;">
                            <?php foreach ($status_options as $key => $status): ?>
                                <option value="<?php echo $key; ?>" <?php echo $order_details['status'] == $key ? 'selected' : ''; ?>>
                                    <?php echo $status['icon'] . ' ' . $status['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <button type="submit" name="update_status" class="filter-btn">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="modal-actions">
                <a href="orders.php" class="modal-btn modal-btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Orders
                </a>
            </div>
        </div>
    </div>
    
    <script>
        function closeModal() {
            window.location.href = 'orders.php';
        }
        
        // Close modal when clicking outside
        document.getElementById('orderModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
    <?php endif; ?>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time Admin Panel</p>
            <p style="margin-top: 0.5rem;">Orders Management • <?php echo count($orders); ?> orders displayed</p>
        </div>
    </footer>
    
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.querySelector('.modal-overlay')) {
                window.location.href = 'orders.php';
            }
        });
    </script>
</body>
</html>
