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

// Get date range from request
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['type'] ?? 'daily';

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}

// Quick date filters
$quick_filters = [
    'today' => date('Y-m-d'),
    'yesterday' => date('Y-m-d', strtotime('-1 day')),
    'week' => date('Y-m-d', strtotime('monday this week')),
    'month' => date('Y-m-01'),
    '30days' => date('Y-m-d', strtotime('-30 days'))
];

// ===== 1. SALES OVERVIEW =====
$overview = [
    'total_orders' => 0,
    'total_revenue' => 0,
    'avg_order_value' => 0,
    'total_items' => 0,
    'unique_customers' => 0
];

try {
    // Check if orders table exists
    $tables = $pdo->query("SHOW TABLES LIKE 'orders'")->fetchAll();
    if (!empty($tables)) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT o.id) as total_orders,
                COALESCE(SUM(o.total_amount), 0) as total_revenue,
                COALESCE(AVG(o.total_amount), 0) as avg_order_value,
                COALESCE(SUM(oi.quantity), 0) as total_items,
                COUNT(DISTINCT o.customer_phone) as unique_customers
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE DATE(o.order_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$date_from, $date_to]);
        $overview = $stmt->fetch();
        
        if (!$overview) {
            $overview = [
                'total_orders' => 0,
                'total_revenue' => 0,
                'avg_order_value' => 0,
                'total_items' => 0,
                'unique_customers' => 0
            ];
        }
    }
} catch (PDOException $e) {
    // Log error but continue with empty data
    error_log("Reports error: " . $e->getMessage());
}

// ===== 2. DAILY SALES FOR CHART =====
$chart_data = [];
try {
    if ($report_type == 'daily') {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(order_date) as date,
                COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue
            FROM orders 
            WHERE DATE(order_date) BETWEEN ? AND ?
            GROUP BY DATE(order_date)
            ORDER BY date
        ");
    } elseif ($report_type == 'weekly') {
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(order_date, '%Y-W%v') as date,
                COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue
            FROM orders 
            WHERE DATE(order_date) BETWEEN ? AND ?
            GROUP BY YEAR(order_date), WEEK(order_date)
            ORDER BY YEAR(order_date), WEEK(order_date)
        ");
    } else { // monthly
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(order_date, '%Y-%m') as date,
                COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue
            FROM orders 
            WHERE DATE(order_date) BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(order_date, '%Y-%m')
            ORDER BY date
        ");
    }
    $stmt->execute([$date_from, $date_to]);
    $chart_data = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Chart data error: " . $e->getMessage());
}

// ===== 3. TOP SELLING ITEMS =====
$top_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            oi.item_name,
            COUNT(*) as order_count,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.subtotal) as revenue,
            AVG(oi.price) as avg_price
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY oi.item_name
        ORDER BY total_quantity DESC
        LIMIT 10
    ");
    $stmt->execute([$date_from, $date_to]);
    $top_items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Top items error: " . $e->getMessage());
}

// ===== 4. CATEGORY PERFORMANCE =====
$categories = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            m.category,
            COUNT(DISTINCT o.id) as order_id,
            COALESCE(SUM(oi.quantity), 0) as items_sold,
            COALESCE(SUM(oi.subtotal), 0) as revenue
        FROM menu_items m
        LEFT JOIN order_items oi ON m.name = oi.item_name
        LEFT JOIN orders o ON oi.order_id = o.id AND DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY m.category
        ORDER BY revenue DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Category error: " . $e->getMessage());
}

// ===== 5. PAYMENT METHODS =====
$payments = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            payment_method,
            COUNT(*) as count,
            COALESCE(SUM(total_amount), 0) as total
        FROM orders 
        WHERE DATE(order_date) BETWEEN ? AND ?
        GROUP BY payment_method
    ");
    $stmt->execute([$date_from, $date_to]);
    $payments = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Payment methods error: " . $e->getMessage());
}

// ===== 6. HOURLY DISTRIBUTION =====
$hourly = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            HOUR(order_date) as hour,
            COUNT(*) as orders,
            COALESCE(SUM(total_amount), 0) as revenue
        FROM orders 
        WHERE DATE(order_date) BETWEEN ? AND ?
        GROUP BY HOUR(order_date)
        ORDER BY hour
    ");
    $stmt->execute([$date_from, $date_to]);
    $hourly = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Hourly error: " . $e->getMessage());
}

// ===== 7. PREPARE DATA FOR CHARTS =====
$chart_labels = [];
$chart_orders = [];
$chart_revenue = [];

foreach ($chart_data as $row) {
    $chart_labels[] = $row['date'];
    $chart_orders[] = $row['orders'];
    $chart_revenue[] = $row['revenue'];
}

// Previous period comparison
$days_diff = (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24);
$prev_date_from = date('Y-m-d', strtotime($date_from . ' -' . ($days_diff + 1) . ' days'));
$prev_date_to = date('Y-m-d', strtotime($date_from . ' -1 day'));

$prev_revenue = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as revenue
        FROM orders 
        WHERE DATE(order_date) BETWEEN ? AND ?
    ");
    $stmt->execute([$prev_date_from, $prev_date_to]);
    $prev_revenue = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Previous period error: " . $e->getMessage());
}

$growth = $prev_revenue > 0 ? round(($overview['total_revenue'] - $prev_revenue) / $prev_revenue * 100, 1) : ($overview['total_revenue'] > 0 ? 100 : 0);

// Payment method labels
$payment_labels = [
    'cash' => '💵 Cash',
    'card' => '💳 Card',
    'tng' => '📱 Touch n Go',
    'online' => '🌐 Online'
];

// Category icons
$category_icons = [
    'Coffee Classics' => '☕',
    'Matcha Cravings' => '🍵',
    'Chocolate Series' => '🍫',
    'Tea Selection' => '🫖',
    'Refreshers' => '🧃',
    'Cafe Specials' => '✨'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Yadang's Time Admin</title>
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
        
        /* ===== QUICK FILTERS ===== */
        .quick-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        
        .quick-filter-btn {
            background: rgba(26, 44, 34, 0.7);
            border: 1px solid rgba(250, 161, 143, 0.3);
            color: var(--text-light);
            padding: 0.5rem 1rem;
            border-radius: 30px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .quick-filter-btn:hover {
            border-color: var(--accent-color);
            background: rgba(250, 161, 143, 0.1);
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
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .stat-title {
            color: rgba(232, 223, 208, 0.7);
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
        
        .stat-icon.revenue { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        .stat-icon.orders { background: rgba(52, 152, 219, 0.2); color: var(--info-color); }
        .stat-icon.customers { background: rgba(155, 89, 182, 0.2); color: #9b59b6; }
        .stat-icon.items { background: rgba(241, 196, 15, 0.2); color: var(--warning-color); }
        
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
        
        /* ===== CHARTS SECTION ===== */
        .charts-section {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 1024px) {
            .charts-section {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-card {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
        }
        
        .chart-title {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-color);
        }
        
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        /* ===== TABLES SECTION ===== */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .table-card {
            background: var(--container-bg);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .table-title {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-color);
        }
        
        .export-btn {
            background: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            background: var(--accent-color);
            color: var(--dark-text);
        }
        
        .reports-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .reports-table th {
            text-align: left;
            padding: 0.8rem 0.5rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
            color: var(--accent-color);
            font-weight: 600;
        }
        
        .reports-table td {
            padding: 0.8rem 0.5rem;
            border-bottom: 1px solid rgba(232, 223, 208, 0.1);
        }
        
        .reports-table tr:hover td {
            background: rgba(250, 161, 143, 0.05);
        }
        
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            display: inline-block;
        }
        
        .badge-cash { background: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        .badge-card { background: rgba(52, 152, 219, 0.2); color: var(--info-color); }
        .badge-tng { background: rgba(155, 89, 182, 0.2); color: #9b59b6; }
        .badge-online { background: rgba(241, 196, 15, 0.2); color: var(--warning-color); }
        
        /* ===== PROGRESS BAR ===== */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.2rem;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--accent-color);
            border-radius: 3px;
            transition: width 0.5s ease;
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
            
            .quick-filters {
                justify-content: center;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .tables-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-container {
                height: 250px;
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
                <p>Reports & Analytics</p>
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
                <i class="fas fa-chart-line"></i>
                Sales Reports
            </h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <button class="btn-primary" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Export Report
                </button>
            </div>
        </div>
        
        <!-- Quick Filters -->
        <div class="quick-filters">
            <a href="?date_from=<?php echo $quick_filters['today']; ?>&date_to=<?php echo $quick_filters['today']; ?>&type=daily" class="quick-filter-btn">
                <i class="fas fa-sun"></i> Today
            </a>
            <a href="?date_from=<?php echo $quick_filters['yesterday']; ?>&date_to=<?php echo $quick_filters['yesterday']; ?>&type=daily" class="quick-filter-btn">
                <i class="fas fa-calendar-day"></i> Yesterday
            </a>
            <a href="?date_from=<?php echo $quick_filters['week']; ?>&date_to=<?php echo $quick_filters['today']; ?>&type=weekly" class="quick-filter-btn">
                <i class="fas fa-calendar-week"></i> This Week
            </a>
            <a href="?date_from=<?php echo $quick_filters['month']; ?>&date_to=<?php echo $quick_filters['today']; ?>&type=monthly" class="quick-filter-btn">
                <i class="fas fa-calendar-alt"></i> This Month
            </a>
            <a href="?date_from=<?php echo $quick_filters['30days']; ?>&date_to=<?php echo $quick_filters['today']; ?>&type=daily" class="quick-filter-btn">
                <i class="fas fa-undo-alt"></i> Last 30 Days
            </a>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <h3 class="filter-title">
                <i class="fas fa-filter"></i>
                Custom Date Range
            </h3>
            
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Report Type</label>
                        <select name="type" class="filter-select">
                            <option value="daily" <?php echo $report_type == 'daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo $report_type == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly" <?php echo $report_type == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
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
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="filter-btn">
                        <i class="fas fa-sync-alt"></i> Generate Report
                    </button>
                    <a href="reports.php" class="reset-btn">
                        <i class="fas fa-redo"></i> Reset (Last 30 Days)
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Revenue</div>
                    <div class="stat-icon revenue">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
                <div class="stat-value">RM <?php echo number_format($overview['total_revenue'], 2); ?></div>
                <div class="stat-change <?php echo $growth >= 0 ? 'change-up' : 'change-down'; ?>">
                    <i class="fas fa-arrow-<?php echo $growth >= 0 ? 'up' : 'down'; ?>"></i>
                    <span><?php echo abs($growth); ?>% vs previous period</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Orders</div>
                    <div class="stat-icon orders">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $overview['total_orders']; ?></div>
                <div class="stat-change">
                    <span>Avg: RM <?php echo number_format($overview['avg_order_value'], 2); ?>/order</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Unique Customers</div>
                    <div class="stat-icon customers">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $overview['unique_customers']; ?></div>
                <div class="stat-change">
                    <span><?php echo $overview['total_items']; ?> items sold</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Items Per Order</div>
                    <div class="stat-icon items">
                        <i class="fas fa-box"></i>
                    </div>
                </div>
                <div class="stat-value">
                    <?php 
                    $items_per_order = $overview['total_orders'] > 0 
                        ? round($overview['total_items'] / $overview['total_orders'], 1) 
                        : 0;
                    echo $items_per_order; 
                    ?>
                </div>
                <div class="stat-change">
                    <span>Average basket size</span>
                </div>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="charts-section">
            <!-- Revenue Chart -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <i class="fas fa-chart-line"></i>
                    Revenue & Orders Trend
                </h3>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
            
            <!-- Payment Methods Chart -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <i class="fas fa-chart-pie"></i>
                    Payment Methods
                </h3>
                <div class="chart-container">
                    <canvas id="paymentChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Tables Grid -->
        <div class="tables-grid">
            <!-- Top Selling Items -->
            <div class="table-card">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-crown"></i>
                        Top Selling Items
                    </h3>
                    <button class="export-btn" onclick="exportItems()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
                
                <table class="reports-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Orders</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $max_qty = !empty($top_items) ? max(array_column($top_items, 'total_quantity')) : 1;
                        foreach ($top_items as $item): 
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                </td>
                                <td><?php echo $item['total_quantity']; ?></td>
                                <td><?php echo $item['order_count']; ?></td>
                                <td>RM <?php echo number_format($item['revenue'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" style="padding-top: 0; border-bottom: none;">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo ($item['total_quantity'] / $max_qty * 100); ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($top_items)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 2rem;">
                                No data available for this period
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        
        <!-- Hourly & Payment Tables -->
        <div class="tables-grid">
            <!-- Hourly Distribution -->
            <div class="table-card">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-clock"></i>
                        Peak Hours
                    </h3>
                </div>
                
                <table class="reports-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Orders</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $max_hour_orders = !empty($hourly) ? max(array_column($hourly, 'orders')) : 1;
                        foreach ($hourly as $hour): 
                        ?>
                            <tr>
                                <td>
                                    <?php 
                                    $hour_num = $hour['hour'];
                                    $ampm = $hour_num >= 12 ? 'PM' : 'AM';
                                    $display_hour = $hour_num > 12 ? $hour_num - 12 : $hour_num;
                                    $display_hour = $display_hour == 0 ? 12 : $display_hour;
                                    echo sprintf('%02d:00', $hour_num) . ' ' . $ampm;
                                    ?>
                                </td>
                                <td><?php echo $hour['orders']; ?></td>
                                <td>RM <?php echo number_format($hour['revenue'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="3" style="padding-top: 0; border-bottom: none;">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo ($hour['orders'] / $max_hour_orders * 100); ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($hourly)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 2rem;">
                                No data available for this period
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Payment Methods -->
            <div class="table-card">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-credit-card"></i>
                        Payment Methods
                    </h3>
                </div>
                
                <table class="reports-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Orders</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $max_payment = !empty($payments) ? max(array_column($payments, 'count')) : 1;
                        foreach ($payments as $payment): 
                        ?>
                            <tr>
                                <td>
                                    <span class="badge badge-<?php echo $payment['payment_method']; ?>">
                                        <?php echo $payment_labels[$payment['payment_method']] ?? $payment['payment_method']; ?>
                                    </span>
                                </td>
                                <td><?php echo $payment['count']; ?></td>
                                <td>RM <?php echo number_format($payment['total'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="3" style="padding-top: 0; border-bottom: none;">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo ($payment['count'] / $max_payment * 100); ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 2rem;">
                                No data available for this period
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time Admin Panel</p>
            <p style="margin-top: 0.5rem; opacity: 0.7;">
                <?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?>
            </p>
        </div>
    </footer>
    
    <script>
        // Revenue Chart
        const ctx1 = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Revenue (RM)',
                    data: <?php echo json_encode($chart_revenue); ?>,
                    borderColor: '#FAA18F',
                    backgroundColor: 'rgba(250, 161, 143, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Orders',
                    data: <?php echo json_encode($chart_orders); ?>,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        labels: { color: '#E8DFD0' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.dataset.label.includes('Revenue')) {
                                    label += 'RM ' + context.parsed.y.toFixed(2);
                                } else {
                                    label += context.parsed.y;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(232, 223, 208, 0.1)' },
                        ticks: { color: '#E8DFD0' }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: { color: 'rgba(232, 223, 208, 0.1)' },
                        ticks: { 
                            color: '#E8DFD0',
                            callback: function(value) {
                                return 'RM ' + value;
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { 
                            color: '#E8DFD0',
                            stepSize: 1
                        }
                    }
                }
            }
        });
        
        // Payment Methods Chart
        const ctx2 = document.getElementById('paymentChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: <?php 
                $payment_chart_labels = array_map(function($p) use ($payment_labels) {
                    return $payment_labels[$p['payment_method']] ?? $p['payment_method'];
                }, $payments);
                echo json_encode(array_values($payment_chart_labels)); 
                ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($payments, 'total')); ?>,
                    backgroundColor: [
                        '#2ecc71',
                        '#3498db',
                        '#9b59b6',
                        '#f39c12'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#E8DFD0' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw || 0;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: RM ${value.toFixed(2)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        
        // Export to CSV function
        function exportToCSV() {
            let csv = [];
            
            // Header
            csv.push(['Yadang\'s Time Sales Report']);
            csv.push(['Period:', '<?php echo $date_from; ?> to <?php echo $date_to; ?>']);
            csv.push(['Generated:', new Date().toLocaleString()]);
            csv.push([]);
            
            // Overview
            csv.push(['SALES OVERVIEW']);
            csv.push(['Total Revenue', 'RM <?php echo number_format($overview['total_revenue'], 2); ?>']);
            csv.push(['Total Orders', '<?php echo $overview['total_orders']; ?>']);
            csv.push(['Average Order Value', 'RM <?php echo number_format($overview['avg_order_value'], 2); ?>']);
            csv.push(['Unique Customers', '<?php echo $overview['unique_customers']; ?>']);
            csv.push(['Total Items Sold', '<?php echo $overview['total_items']; ?>']);
            csv.push([]);
            
            // Top Items
            csv.push(['TOP SELLING ITEMS']);
            csv.push(['Item', 'Quantity', 'Orders', 'Revenue (RM)']);
            <?php foreach ($top_items as $item): ?>
            csv.push([
                '<?php echo addslashes($item['item_name']); ?>',
                '<?php echo $item['total_quantity']; ?>',
                '<?php echo $item['order_count']; ?>',
                '<?php echo number_format($item['revenue'], 2); ?>'
            ]);
            <?php endforeach; ?>
            csv.push([]);
            
            // Category
            csv.push(['CATEGORY PERFORMANCE']);
            csv.push(['Category', 'Items Sold', 'Orders', 'Revenue (RM)']);
            <?php foreach ($categories as $cat): ?>
            csv.push([
                '<?php echo addslashes($cat['category']); ?>',
                '<?php echo $cat['items_sold']; ?>',
                '<?php echo $cat['orders']; ?>',
                '<?php echo number_format($cat['revenue'], 2); ?>'
            ]);
            <?php endforeach; ?>
            
            // Convert to CSV and download
            let csvContent = csv.map(row => row.join(',')).join('\n');
            let blob = new Blob([csvContent], { type: 'text/csv' });
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = 'yadangs_report_<?php echo date('Ymd'); ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
        
        function exportItems() {
            let csv = [];
            csv.push(['Item', 'Quantity', 'Orders', 'Revenue (RM)']);
            <?php foreach ($top_items as $item): ?>
            csv.push([
                '<?php echo addslashes($item['item_name']); ?>',
                '<?php echo $item['total_quantity']; ?>',
                '<?php echo $item['order_count']; ?>',
                '<?php echo number_format($item['revenue'], 2); ?>'
            ]);
            <?php endforeach; ?>
            
            let csvContent = csv.map(row => row.join(',')).join('\n');
            let blob = new Blob([csvContent], { type: 'text/csv' });
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = 'yadangs_top_items_<?php echo date('Ymd'); ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
