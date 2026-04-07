<?php
session_start();
require_once '../includes/db.php';

// Log activity if admin was logged in
if (isset($_SESSION['admin_id'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address) VALUES (?, 'logout', 'Logged out', ?)");
        $stmt->execute([$_SESSION['admin_id'], $_SERVER['REMOTE_ADDR']]);
    } catch (PDOException $e) {
        // Table might not exist - ignore
    }
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php');
exit();
?>
