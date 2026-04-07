<?php
session_start();
require_once '../includes/db.php';

// Error reporting untuk debugging (boleh disable dalam production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$admin_id = $_SESSION['admin_id'] ?? 1;
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';

$success_message = '';
$error_message = '';

// Pastikan direktori upload wujud dan boleh ditulis
$upload_dir = '../uploads/menu_items/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}
// Set permissions
chmod($upload_dir, 0777);

// ===== HANDLE ADD MENU ITEM =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_item'])) {
    try {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price']);
        $category = $_POST['category'];
        $is_available = isset($_POST['is_available']) ? 1 : 0;
        $calories = intval($_POST['calories'] ?? 0);
        $prep_time = intval($_POST['prep_time'] ?? 5);
        
        // Validation
        if (empty($name)) {
            throw new Exception("Item name is required.");
        }
        if ($price <= 0) {
            throw new Exception("Price must be greater than 0.");
        }
        
        // Handle image upload
        $image_url = '';
        if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] == UPLOAD_ERR_OK) {
            $image_url = uploadImage($_FILES['item_image']);
        } elseif (isset($_FILES['item_image']) && $_FILES['item_image']['error'] != UPLOAD_ERR_NO_FILE) {
            throw new Exception(getUploadErrorMessage($_FILES['item_image']['error']));
        }
        
        // Insert into database
        $stmt = $pdo->prepare("
            INSERT INTO menu_items (name, description, price, category, image_url, is_available, calories, prep_time) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$name, $description, $price, $category, $image_url, $is_available, $calories, $prep_time])) {
            // Log activity
            $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
            $log->execute([$admin_id, 'add_menu', "Added menu item: $name", $_SERVER['REMOTE_ADDR']]);
            
            $success_message = "Menu item added successfully!";
            
            // Redirect to refresh page and clear POST data
            header("Location: menu.php?success=added");
            exit();
        } else {
            throw new Exception("Failed to add menu item to database.");
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ===== HANDLE EDIT MENU ITEM =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_item'])) {
    try {
        $item_id = intval($_POST['item_id']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price']);
        $category = $_POST['category'];
        $is_available = isset($_POST['is_available']) ? 1 : 0;
        $calories = intval($_POST['calories'] ?? 0);
        $prep_time = intval($_POST['prep_time'] ?? 5);
        
        // Validation
        if (empty($name)) {
            throw new Exception("Item name is required.");
        }
        if ($price <= 0) {
            throw new Exception("Price must be greater than 0.");
        }
        
        // Get current image
        $stmt = $pdo->prepare("SELECT image_url FROM menu_items WHERE id = ?");
        $stmt->execute([$item_id]);
        $current = $stmt->fetch();
        
        if (!$current) {
            throw new Exception("Menu item not found.");
        }
        
        $image_url = $current['image_url'];
        
        // Handle image upload if new image
        if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] == UPLOAD_ERR_OK) {
            // Upload new image
            $new_image_url = uploadImage($_FILES['item_image']);
            
            // Delete old image if exists
            if ($image_url && file_exists($upload_dir . $image_url)) {
                unlink($upload_dir . $image_url);
            }
            
            $image_url = $new_image_url;
        } elseif (isset($_FILES['item_image']) && $_FILES['item_image']['error'] != UPLOAD_ERR_NO_FILE) {
            throw new Exception(getUploadErrorMessage($_FILES['item_image']['error']));
        }
        
        // Update database
        $stmt = $pdo->prepare("
            UPDATE menu_items 
            SET name = ?, description = ?, price = ?, category = ?, image_url = ?, is_available = ?, calories = ?, prep_time = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([$name, $description, $price, $category, $image_url, $is_available, $calories, $prep_time, $item_id])) {
            $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
            $log->execute([$admin_id, 'edit_menu', "Updated menu item: $name", $_SERVER['REMOTE_ADDR']]);
            
            $success_message = "Menu item updated successfully!";
            
            // Redirect to refresh page and clear POST data
            header("Location: menu.php?success=updated");
            exit();
        } else {
            throw new Exception("Failed to update menu item.");
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ===== HANDLE DELETE MENU ITEM =====
if (isset($_GET['delete'])) {
    try {
        $item_id = intval($_GET['delete']);
        
        // Get image to delete
        $stmt = $pdo->prepare("SELECT name, image_url FROM menu_items WHERE id = ?");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();
        
        if ($item) {
            // Delete image file
            if ($item['image_url'] && file_exists($upload_dir . $item['image_url'])) {
                unlink($upload_dir . $item['image_url']);
            }
            
            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
            if ($stmt->execute([$item_id])) {
                $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
                $log->execute([$admin_id, 'delete_menu', "Deleted menu item: " . $item['name'], $_SERVER['REMOTE_ADDR']]);
                
                header("Location: menu.php?success=deleted");
                exit();
            }
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ===== HANDLE TOGGLE AVAILABILITY =====
if (isset($_GET['toggle'])) {
    try {
        $item_id = intval($_GET['toggle']);
        
        $stmt = $pdo->prepare("UPDATE menu_items SET is_available = NOT is_available WHERE id = ?");
        if ($stmt->execute([$item_id])) {
            // Get item name for log
            $stmt2 = $pdo->prepare("SELECT name FROM menu_items WHERE id = ?");
            $stmt2->execute([$item_id]);
            $item = $stmt2->fetch();
            
            $log = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
            $log->execute([$admin_id, 'toggle_menu', "Toggled availability: " . $item['name'], $_SERVER['REMOTE_ADDR']]);
            
            header("Location: menu.php?success=toggled");
            exit();
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Handle success messages from redirects
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $success_message = "Menu item added successfully!";
            break;
        case 'updated':
            $success_message = "Menu item updated successfully!";
            break;
        case 'deleted':
            $success_message = "Menu item deleted successfully!";
            break;
        case 'toggled':
            $success_message = "Item availability updated!";
            break;
    }
}

// ===== GET ALL MENU ITEMS =====
$stmt = $pdo->query("
    SELECT * FROM menu_items 
    ORDER BY 
        CASE category
            WHEN 'Coffee Classics' THEN 1
            WHEN 'Matcha Cravings' THEN 2
            WHEN 'Chocolate Series' THEN 3
            WHEN 'Tea Selection' THEN 4
            WHEN 'Refreshers' THEN 5
            WHEN 'Cafe Specials' THEN 6
            ELSE 7
        END,
        name ASC
");
$menu_items = $stmt->fetchAll();

// Get item for editing if ID provided
$edit_item = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([intval($_GET['edit'])]);
    $edit_item = $stmt->fetch();
}

// Categories list (with icons)
$categories = [
    'Coffee Classics' => '☕ Coffee Classics',
    'Matcha Cravings' => '🍵 Matcha Cravings',
    'Chocolate Series' => '🍫 Chocolate Series',
    'Tea Selection' => '🥂 Tea Selection',
    'Refreshers' => '🧃 Refreshers',
    'Cafe Specials' => '✨ Cafe Specials'
];

// Fungsi untuk upload image
function uploadImage($file) {
    global $upload_dir;
    
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    $filename = $file['name'];
    $file_size = $file['size'];
    $file_tmp = $file['tmp_name'];
    
    // Get file extension
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // Validate file type
    if (!in_array($ext, $allowed)) {
        throw new Exception("Invalid image type. Allowed: " . implode(', ', $allowed));
    }
    
    // Validate file size
    if ($file_size > $max_size) {
        throw new Exception("Image size too large. Maximum size is 5MB.");
    }
    
    // Generate unique filename
    $new_filename = uniqid() . '_' . time() . '.' . $ext;
    $upload_path = $upload_dir . $new_filename;
    
    // Upload file
    if (!move_uploaded_file($file_tmp, $upload_path)) {
        throw new Exception("Failed to upload image. Please check directory permissions.");
    }
    
    return $new_filename;
}

// Fungsi untuk mendapatkan mesej error upload
function getUploadErrorMessage($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return "The uploaded file exceeds the upload_max_filesize directive in php.ini.";
        case UPLOAD_ERR_FORM_SIZE:
            return "The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.";
        case UPLOAD_ERR_PARTIAL:
            return "The uploaded file was only partially uploaded.";
        case UPLOAD_ERR_NO_FILE:
            return "No file was uploaded.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Missing a temporary folder.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Failed to write file to disk.";
        case UPLOAD_ERR_EXTENSION:
            return "A PHP extension stopped the file upload.";
        default:
            return "Unknown upload error.";
    }
}

// Check if upload directory is writable
if (!is_writable($upload_dir)) {
    $error_message = "Upload directory is not writable. Please check permissions.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Menu | Yadang's Time Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== DARK GREEN THEME (sama macam sebelumnya) ===== */
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
        
        /* ===== MODAL ===== */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 25, 19, 0.95);
            backdrop-filter: blur(5px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }
        
        .modal-content {
            background: var(--container-bg);
            border-radius: 20px;
            max-width: 600px;
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
            padding: 0.8rem 1rem;
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
            gap: 0.8rem;
            background: rgba(26, 44, 34, 0.5);
            padding: 0.8rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .checkbox-group input {
            width: 20px;
            height: 20px;
            accent-color: var(--accent-color);
            cursor: pointer;
        }
        
        .checkbox-group label {
            cursor: pointer;
        }
        
        .image-preview {
            width: 120px;
            height: 120px;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid var(--accent-color);
            margin-top: 0.5rem;
            background: rgba(26, 44, 34, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .image-preview.empty {
            color: rgba(232, 223, 208, 0.3);
            font-size: 2rem;
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
            font-size: 1rem;
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
        
        /* ===== MENU TABLE ===== */
        .table-card {
            background: var(--container-bg);
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid rgba(250, 161, 143, 0.2);
            overflow-x: auto;
        }
        
        .menu-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        
        .menu-table th {
            text-align: left;
            padding: 1rem 0.5rem;
            border-bottom: 2px solid rgba(250, 161, 143, 0.3);
            color: var(--accent-color);
            font-weight: 600;
        }
        
        .menu-table td {
            padding: 1rem 0.5rem;
            border-bottom: 1px solid rgba(232, 223, 208, 0.1);
            vertical-align: middle;
        }
        
        .menu-table tr:hover td {
            background: rgba(250, 161, 143, 0.05);
        }
        
        .item-thumb {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid var(--accent-color);
        }
        
        .item-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .category-badge {
            background: rgba(250, 161, 143, 0.2);
            color: var(--accent-color);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
            white-space: nowrap;
        }
        
        .price-badge {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-available {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }
        
        .status-unavailable {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }
        
        .action-buttons {
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
            background: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }
        
        .btn-icon.toggle:hover {
            background: var(--warning-color);
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
        
        /* ===== LOADING SPINNER ===== */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: var(--accent-color);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
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
            
            .btn-primary, .btn-secondary {
                width: 100%;
                justify-content: center;
            }
            
            .modal-content {
                padding: 1.5rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
            
            .admin-badge {
                padding: 0.4rem 1rem;
            }
            
            .admin-avatar {
                width: 35px;
                height: 35px;
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
                <p>Menu Management</p>
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
                <i class="fas fa-utensils"></i>
                Manage Menu Items
            </h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <button class="btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Item
                </button>
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
        
        <!-- Menu Table -->
        <div class="table-card">
            <table class="menu-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Price</th>
                        <th>Prep Time</th>
                        <th>Calories</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($menu_items)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 3rem;">
                                <i class="fas fa-utensils" style="font-size: 3rem; color: rgba(250,161,143,0.3);"></i>
                                <p style="margin-top: 1rem;">No menu items found. Click "Add New Item" to create one.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($menu_items as $item): ?>
                            <tr>
                                <td>
                                    <div class="item-thumb">
                                        <?php if (!empty($item['image_url']) && file_exists($upload_dir . $item['image_url'])): ?>
                                            <img src="../uploads/menu_items/<?php echo $item['image_url']; ?>?t=<?php echo time(); ?>" 
                                                 alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                 onerror="this.src='../images/default-drink.jpg'">
                                        <?php else: ?>
                                            <img src="../images/default-drink.jpg" alt="Default">
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                </td>
                                <td>
                                    <span class="category-badge">
                                        <?php 
                                        $icon = '';
                                        switch($item['category']) {
                                            case 'Coffee Classics': $icon = '☕'; break;
                                            case 'Matcha Cravings': $icon = '🍵'; break;
                                            case 'Chocolate Series': $icon = '🍫'; break;
                                            case 'Tea Selection': $icon = '🥂'; break;
                                            case 'Refreshers': $icon = '🧃'; break;
                                            case 'Cafe Specials': $icon = '✨'; break;
                                            default: $icon = '•';
                                        }
                                        echo $icon . ' ' . $item['category']; 
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <small style="color: rgba(232, 223, 208, 0.7);">
                                        <?php 
                                        $desc = htmlspecialchars($item['description'] ?? '');
                                        echo strlen($desc) > 40 ? substr($desc, 0, 40) . '...' : $desc; 
                                        ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="price-badge">RM <?php echo number_format($item['price'], 2); ?></span>
                                </td>
                                <td><?php echo $item['prep_time'] ?? 5; ?> min</td>
                                <td><?php echo $item['calories'] ?? 0; ?> cal</td>
                                <td>
                                    <span class="status-badge <?php echo $item['is_available'] ? 'status-available' : 'status-unavailable'; ?>">
                                        <?php echo $item['is_available'] ? 'Available' : 'Unavailable'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit=<?php echo $item['id']; ?>" class="btn-icon edit" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?toggle=<?php echo $item['id']; ?>" class="btn-icon toggle" title="Toggle Availability">
                                            <i class="fas fa-power-off"></i>
                                        </a>
                                        <a href="?delete=<?php echo $item['id']; ?>" class="btn-icon delete" title="Delete" 
                                           onclick="return confirm('Delete this menu item? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i>
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
    
    <!-- Add Item Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add New Menu Item</h2>
                <button class="modal-close" onclick="closeAddModal()">&times;</button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="addForm">
                <div class="form-group">
                    <label class="form-label">Item Name *</label>
                    <input type="text" name="name" class="form-input" required maxlength="100">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea" placeholder="Describe the drink..." maxlength="500"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Price (RM) *</label>
                        <input type="number" name="price" step="0.01" min="0.01" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-select" required>
                            <?php foreach ($categories as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Prep Time (minutes)</label>
                        <input type="number" name="prep_time" value="5" min="1" max="60" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Calories</label>
                        <input type="number" name="calories" value="0" min="0" max="2000" class="form-input">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Item Image</label>
                    <input type="file" name="item_image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-input" onchange="previewImage(this, 'addPreview')">
                    <div class="image-preview empty" id="addPreview">
                        <i class="fas fa-image"></i>
                    </div>
                    <small style="color: rgba(232,223,208,0.6);">Supported: jpg, jpeg, png, gif, webp (Max 5MB)</small>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_available" id="add_available" checked>
                    <label for="add_available">Available for ordering</label>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" name="add_item" class="modal-btn modal-btn-primary" onclick="this.innerHTML='<span class=\'loading-spinner\'></span> Adding...'">Add Item</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Item Modal -->
    <?php if ($edit_item): ?>
    <div class="modal-overlay" id="editModal" style="display:flex;">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Edit Menu Item</h2>
                <a href="menu.php" class="modal-close">&times;</a>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="item_id" value="<?php echo $edit_item['id']; ?>">
                
                <div class="form-group">
                    <label class="form-label">Item Name *</label>
                    <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($edit_item['name']); ?>" required maxlength="100">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea" maxlength="500"><?php echo htmlspecialchars($edit_item['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Price (RM) *</label>
                        <input type="number" name="price" step="0.01" min="0.01" class="form-input" value="<?php echo $edit_item['price']; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-select" required>
                            <?php foreach ($categories as $key => $value): ?>
                                <option value="<?php echo $key; ?>" <?php echo $edit_item['category'] == $key ? 'selected' : ''; ?>>
                                    <?php echo $value; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Prep Time (minutes)</label>
                        <input type="number" name="prep_time" min="1" max="60" class="form-input" value="<?php echo $edit_item['prep_time'] ?? 5; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Calories</label>
                        <input type="number" name="calories" min="0" max="2000" class="form-input" value="<?php echo $edit_item['calories'] ?? 0; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Item Image</label>
                    <input type="file" name="item_image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-input" onchange="previewImage(this, 'editPreview')">
                    <div class="image-preview" id="editPreview">
                        <?php if (!empty($edit_item['image_url']) && file_exists($upload_dir . $edit_item['image_url'])): ?>
                            <img src="../uploads/menu_items/<?php echo $edit_item['image_url']; ?>?t=<?php echo time(); ?>" alt="Current Image">
                        <?php else: ?>
                            <i class="fas fa-image" style="font-size: 2rem; color: rgba(232,223,208,0.3);"></i>
                        <?php endif; ?>
                    </div>
                    <small style="color: rgba(232,223,208,0.6);">Leave empty to keep current image (Max 5MB)</small>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_available" id="edit_available" <?php echo $edit_item['is_available'] ? 'checked' : ''; ?>>
                    <label for="edit_available">Available for ordering</label>
                </div>
                
                <div class="modal-actions">
                    <a href="menu.php" class="modal-btn modal-btn-secondary">Cancel</a>
                    <button type="submit" name="edit_item" class="modal-btn modal-btn-primary" onclick="this.innerHTML='<span class=\'loading-spinner\'></span> Updating...'">Update Item</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <footer class="main-footer">
        <div class="copyright">
            <p>&copy; 2025 Yadang's Time Admin Panel</p>
            <p style="margin-top: 0.5rem;">Menu Management • <?php echo count($menu_items); ?> items total</p>
        </div>
    </footer>
    
    <script>
        // ===== MODAL FUNCTIONS =====
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            // Reset form
            document.getElementById('addForm').reset();
            document.getElementById('addPreview').innerHTML = '<i class="fas fa-image"></i>';
            document.getElementById('addPreview').classList.add('empty');
        }
        
        // ===== IMAGE PREVIEW =====
        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);
            preview.innerHTML = '';
            preview.classList.remove('empty');
            
            if (input.files && input.files[0]) {
                // Check file size
                if (input.files[0].size > 5 * 1024 * 1024) {
                    alert('File is too large. Maximum size is 5MB.');
                    input.value = '';
                    preview.innerHTML = '<i class="fas fa-image"></i>';
                    preview.classList.add('empty');
                    return;
                }
                
                // Check file type
                const fileType = input.files[0].type;
                if (!fileType.match(/image\/(jpeg|jpg|png|gif|webp)/)) {
                    alert('Invalid file type. Please upload an image.');
                    input.value = '';
                    preview.innerHTML = '<i class="fas fa-image"></i>';
                    preview.classList.add('empty');
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    preview.appendChild(img);
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.innerHTML = '<i class="fas fa-image"></i>';
                preview.classList.add('empty');
            }
        }
        
        // ===== CLOSE MODAL WHEN CLICKING OUTSIDE =====
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                if (event.target.id === 'addModal') {
                    closeAddModal();
                } else {
                    window.location.href = 'menu.php';
                }
            }
        }
        
        // ===== AUTO-HIDE ALERTS =====
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // ===== PREVENT FORM RESUBMISSION =====
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href.split('?')[0]);
        }
        
        // ===== FORM VALIDATION =====
        document.getElementById('addForm')?.addEventListener('submit', function(e) {
            const price = parseFloat(this.price.value);
            if (price <= 0) {
                e.preventDefault();
                alert('Price must be greater than 0.');
            }
        });
        
        document.getElementById('editForm')?.addEventListener('submit', function(e) {
            const price = parseFloat(this.price.value);
            if (price <= 0) {
                e.preventDefault();
                alert('Price must be greater than 0.');
            }
        });
    </script>
</body>
</html>
