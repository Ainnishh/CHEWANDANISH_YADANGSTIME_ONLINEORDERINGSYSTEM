<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Database connection
function connectDB() {
    $host = 'localhost';
    $dbname = 'yadangs_db';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

$db = connectDB();
$user_id = $_SESSION['user_id'] ?? 0;
$nickname = $_SESSION['nickname'] ?? '';
$phone = $_SESSION['phone'] ?? '';

// Get user profile
$stmt = $db->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    $profile = [];
}

$email = isset($profile['email']) ? $profile['email'] : '';
$birthdate = isset($profile['birthdate']) ? $profile['birthdate'] : '';
$profile_pic = isset($profile['profile_picture']) ? $profile['profile_picture'] : '';

$upload_dir = 'uploads/profile_pics/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$message = '';
$message_type = '';

// ===== GET USER'S FEEDBACK =====
$user_feedback = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM feedback 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $user_feedback = $stmt->fetchAll();
} catch (PDOException $e) {
    $user_feedback = [];
}

// ===== GET USER'S CONTACT MESSAGES =====
$user_messages = [];
try {
    // Try to get messages by user_id first
    $stmt = $db->prepare("
        SELECT * FROM contact_messages 
        WHERE user_id = ? 
        ORDER BY 
            CASE 
                WHEN status = 'new' THEN 1
                WHEN status = 'replied' THEN 2
                ELSE 3
            END,
            created_at DESC
    ");
    $stmt->execute([$user_id]);
    $user_messages = $stmt->fetchAll();
    
    // If no messages by user_id, try by email and update them
    if (empty($user_messages) && !empty($email)) {
        $stmt = $db->prepare("
            SELECT * FROM contact_messages 
            WHERE email = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$email]);
        $user_messages = $stmt->fetchAll();
        
        // Update these messages with user_id
        if (!empty($user_messages)) {
            $update = $db->prepare("UPDATE contact_messages SET user_id = ? WHERE email = ?");
            $update->execute([$user_id, $email]);
        }
    }
} catch (PDOException $e) {
    $user_messages = [];
}

// Get admin names for replies
$admins = [];
try {
    $stmt = $db->query("SELECT id, full_name FROM admin");
    $admins = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $admins = [];
}

// ===== HANDLE PROFILE UPDATE =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_nickname = trim($_POST['nickname']);
    $new_phone = trim($_POST['phone']);
    $new_email = trim($_POST['email'] ?? '');  
    $new_birthdate = $_POST['birthdate'] ?? '';

    // Handle profile picture upload
    $profile_pic_name = $profile_pic; 
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg', 'image/webp'];
        $file_type = $_FILES['profile_pic']['type'];
        $file_size = $_FILES['profile_pic']['size'];
        
        if (in_array($file_type, $allowed_types) && $file_size < 5 * 1024 * 1024) {
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $profile_pic_name = 'user_' . $user_id . '_' . time() . '.' . $ext;
            $upload_path = $upload_dir . $profile_pic_name;
            
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_path)) {
                if ($profile_pic && file_exists($upload_dir . $profile_pic)) {
                    unlink($upload_dir . $profile_pic);
                }
            } else {
                $message = "Failed to upload image.";
                $message_type = "error";
            }
        } else {
            $message = "Invalid file type or size too large (max 5MB).";
            $message_type = "error";
        }
    }

    if (!empty($new_nickname) && !empty($new_phone)) {
        try {
            // Update session
            $_SESSION['nickname'] = $new_nickname;
            $_SESSION['phone'] = $new_phone;

            $stmt_check = $db->prepare("SELECT COUNT(*) FROM user_profiles WHERE user_id = ?");
            $stmt_check->execute([$user_id]);
            $exists = $stmt_check->fetchColumn() > 0;

            if ($exists) {
                $stmt = $db->prepare("UPDATE user_profiles SET profile_picture=?, nickname=?, phone=?, email=?, birthdate=? WHERE user_id=?");
                $stmt->execute([$profile_pic_name, $new_nickname, $new_phone, $new_email, $new_birthdate, $user_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO user_profiles (user_id, profile_picture, nickname, phone, email, birthdate) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $profile_pic_name, $new_nickname, $new_phone, $new_email, $new_birthdate]);
            }

            $message = "Profile updated successfully!";
            $message_type = "success";

            // Refresh profile data
            $stmt = $db->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            $profile_pic = isset($profile['profile_picture']) ? $profile['profile_picture'] : '';
            $email = isset($profile['email']) ? $profile['email'] : '';
            
            // Update session email
            $_SESSION['email'] = $email;
            
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = "Nickname and phone are required.";
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings | Yadang's Time</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
            --info-color: #3498db;
            --danger-color: #e74c3c;
            --star-color: #FFD700;
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
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 2rem auto;
        }

        /* Profile Settings Card */
        .profile-card {
            background: var(--gradient-light);
            border-radius: 25px;
            padding: 2.5rem;
            box-shadow: 0 15px 40px var(--shadow-color);
            border: 1px solid rgba(250, 161, 143, 0.3);
            margin-bottom: 2rem;
        }

        h1 {
            color: var(--text-light);
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--accent-color);
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 2.2rem;
        }

        h1 i {
            color: var(--accent-color);
        }

        h2 {
            color: var(--text-light);
            margin: 2rem 0 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--accent-color);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.8rem;
        }

        h2 i {
            color: var(--accent-color);
        }

        .form-group {
            margin-bottom: 1.8rem;
        }

        label {
            display: block;
            margin-bottom: 0.6rem;
            color: var(--accent-color);
            font-weight: 600;
            font-size: 1.1rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid rgba(250, 161, 143, 0.3);
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-light);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 3px rgba(250, 161, 143, 0.2);
        }

        .btn {
            background: linear-gradient(135deg, var(--accent-color), var(--light-accent));
            color: var(--dark-text);
            border: none;
            padding: 14px 35px;
            border-radius: 12px;
            font-size: 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(250, 161, 143, 0.4);
        }

        .btn-back {
            background: transparent;
            border: 2px solid var(--accent-color);
            color: var(--accent-color);
            margin-right: 15px;
        }

        .btn-back:hover {
            background: var(--accent-color);
            color: var(--dark-text);
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 0.9rem;
        }

        .message {
            padding: 18px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 600;
            font-size: 1.1rem;
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .success {
            background: rgba(46, 204, 113, 0.15);
            border: 2px solid var(--success-color);
            color: var(--success-color);
        }

        .error {
            background: rgba(231, 76, 60, 0.15);
            border: 2px solid var(--danger-color);
            color: var(--danger-color);
        }

        .profile-avatar-section {
            text-align: center;
            margin-bottom: 3rem;
            position: relative;
        }

        .avatar-container {
            position: relative;
            display: inline-block;
            margin-bottom: 1.5rem;
        }

        .avatar-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            border: 5px solid var(--accent-color);
            background: var(--accent-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-circle .placeholder {
            font-size: 4rem;
            color: var(--dark-text);
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: var(--accent-color);
            color: var(--dark-text);
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid var(--text-light);
            transition: all 0.3s;
            font-size: 1.2rem;
        }

        .avatar-upload-btn:hover {
            transform: scale(1.1);
            background: var(--light-accent);
        }

        #profilePicInput {
            display: none;
        }

        .avatar-username {
            font-size: 1.8rem;
            color: var(--text-light);
            margin-top: 1rem;
            font-weight: 700;
        }

        .avatar-phone {
            color: var(--accent-color);
            font-size: 1.1rem;
            margin-top: 0.5rem;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(250, 161, 143, 0.2);
        }

        .file-info {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.6);
            margin-top: 0.5rem;
            text-align: center;
        }

        /* Messages Section */
        .messages-section {
            margin-top: 2rem;
        }

        .messages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .message-card {
            background: var(--gradient-light);
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

        .message-card.new {
            border-left: 4px solid var(--warning-color);
        }

        .message-card.replied {
            border-bottom: 4px solid var(--success-color);
        }

        .message-card.urgent {
            border-right: 4px solid var(--danger-color);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .message-subject {
            font-weight: 600;
            color: var(--accent-color);
        }

        .message-date {
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.6);
        }

        .message-content {
            background: rgba(26, 44, 34, 0.5);
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            border-left: 3px solid var(--accent-color);
            max-height: 100px;
            overflow-y: auto;
        }

        .message-content p {
            margin: 0;
        }

        .admin-reply {
            background: rgba(46, 204, 113, 0.1);
            border-left: 4px solid var(--success-color);
            padding: 1rem;
            margin-top: 1rem;
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
            font-style: italic;
            color: rgba(232, 223, 208, 0.9);
        }

        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-size: 0.7rem;
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
            margin-left: 0.5rem;
        }

        .empty-messages {
            text-align: center;
            padding: 3rem;
            background: rgba(26, 44, 34, 0.5);
            border-radius: 15px;
            grid-column: 1/-1;
        }

        .empty-messages i {
            font-size: 3rem;
            color: rgba(250, 161, 143, 0.3);
            margin-bottom: 1rem;
        }

        .message-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            font-size: 0.8rem;
            color: rgba(232, 223, 208, 0.5);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .profile-card {
                padding: 1.5rem;
            }
            
            .messages-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
                gap: 1rem;
            }
            
            .btn-back, .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Profile Settings Card -->
        <div class="profile-card">
            <h1><i class="fas fa-user-cog"></i> Profile Settings</h1>

            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas <?php echo $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data">
                <!-- Profile Picture Section -->
                <div class="profile-avatar-section">
                    <div class="avatar-container">
                        <div class="avatar-circle" id="avatarPreview">
                            <?php if ($profile_pic && file_exists($upload_dir . $profile_pic)): ?>
                                <img src="<?php echo htmlspecialchars($upload_dir . $profile_pic); ?>" alt="Profile Picture">
                            <?php else: ?>
                                <div class="placeholder">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <label for="profilePicInput" class="avatar-upload-btn" title="Change photo">
                            <i class="fas fa-camera"></i>
                        </label>
                        <input type="file" id="profilePicInput" name="profile_pic" accept="image/*" onchange="previewImage(event)">
                    </div>
                    
                    <div class="avatar-username"><?php echo htmlspecialchars($nickname); ?></div>
                    <div class="avatar-phone">📱 <?php echo htmlspecialchars($phone); ?></div>
                    <div class="file-info">Max size: 5MB • JPG, PNG, GIF, WebP</div>
                </div>

                <!-- Form Fields -->
                <div class="form-group">
                    <label for="nickname"><i class="fas fa-signature"></i> Nickname *</label>
                    <input type="text" id="nickname" name="nickname" value="<?php echo htmlspecialchars($nickname); ?>" required>
                </div>

                <div class="form-group">
                    <label for="phone"><i class="fas fa-phone"></i> Phone Number *</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required>
                </div>

                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email (For contact messages)</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" 
                           placeholder="Enter your email to receive replies">
                    <small style="color: rgba(232,223,208,0.5); display: block; margin-top: 0.3rem;">
                        <i class="fas fa-info-circle"></i> Your messages and admin replies will appear below
                    </small>
                </div>

                <div class="form-group">
                    <label for="birthdate"><i class="fas fa-birthday-cake"></i> Birthdate (Optional)</label>
                    <input type="date" id="birthdate" name="birthdate" value="<?php echo htmlspecialchars($birthdate); ?>">
                </div>

                <!-- Action Buttons -->
                <div class="form-actions">
                    <a href="home.php" class="btn btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Home
                    </a>
                    <button type="submit" class="btn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Feedback History Section -->
        <?php if (!empty($user_feedback)): ?>
            <div class="messages-section">
                <h2><i class="fas fa-star"></i> Your Feedback History</h2>
                
                <div class="messages-grid">
                    <?php foreach ($user_feedback as $fb): ?>
                        <div class="message-card 
                            <?php echo $fb['status'] == 'new' ? 'new' : ''; ?>
                            <?php echo $fb['status'] == 'replied' ? 'replied' : ''; ?>">
                            
                            <div class="message-header">
                                <span class="message-subject">
                                    <?php echo !empty($fb['subject']) ? htmlspecialchars($fb['subject']) : 'Feedback'; ?>
                                </span>
                                <div>
                                    <span class="status-badge status-<?php echo $fb['status']; ?>">
                                        <?php echo ucfirst($fb['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="message-date">
                                <i class="far fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($fb['created_at'])); ?>
                                <span style="margin-left: 1rem;">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star" style="color: <?php echo $i <= $fb['rating'] ? '#FFD700' : 'rgba(255,215,0,0.2)'; ?>; font-size: 0.7rem;"></i>
                                    <?php endfor; ?>
                                </span>
                            </div>
                            
                            <div class="message-content">
                                <p><?php echo nl2br(htmlspecialchars($fb['message'])); ?></p>
                            </div>
                            
                            <?php if (!empty($fb['admin_reply'])): ?>
                                <div class="admin-reply">
                                    <div class="reply-header">
                                        <i class="fas fa-reply"></i>
                                        <span>Admin Response</span>
                                        <span style="margin-left: auto; font-size: 0.7rem;">
                                            <?php echo date('d M Y', strtotime($fb['replied_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="reply-content">
                                        <?php echo nl2br(htmlspecialchars($fb['admin_reply'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Contact Messages History Section -->
        <?php if (!empty($email)): ?>
            <div class="messages-section">
                <h2><i class="fas fa-history"></i> Your Message History</h2>
                
                <?php if (empty($user_messages)): ?>
                    <div class="empty-messages">
                        <i class="fas fa-inbox"></i>
                        <h3>No messages yet</h3>
                        <p>When you send messages via the contact form, they will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="messages-grid">
                        <?php foreach ($user_messages as $msg): ?>
                            <div class="message-card 
                                <?php echo $msg['status'] == 'new' ? 'new' : ''; ?>
                                <?php echo $msg['status'] == 'replied' ? 'replied' : ''; ?>
                                <?php echo $msg['is_urgent'] ? 'urgent' : ''; ?>">
                                
                                <div class="message-header">
                                    <span class="message-subject">
                                        <?php echo !empty($msg['subject']) ? htmlspecialchars($msg['subject']) : 'General Inquiry'; ?>
                                    </span>
                                    <div>
                                        <?php if ($msg['is_urgent']): ?>
                                            <span class="urgent-badge">URGENT</span>
                                        <?php endif; ?>
                                        <span class="status-badge status-<?php echo $msg['status']; ?>">
                                            <?php echo ucfirst($msg['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="message-date">
                                    <i class="far fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($msg['created_at'])); ?>
                                </div>
                                
                                <div class="message-content">
                                    <p><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                </div>
                                
                                <?php if (!empty($msg['admin_reply'])): ?>
                                    <div class="admin-reply">
                                        <div class="reply-header">
                                            <i class="fas fa-reply"></i>
                                            <span>Admin Response</span>
                                            <span style="margin-left: auto; font-size: 0.7rem;">
                                                <?php echo date('d M Y', strtotime($msg['replied_at'])); ?>
                                            </span>
                                        </div>
                                        <div class="reply-content">
                                            <?php echo nl2br(htmlspecialchars($msg['admin_reply'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="message-footer">
                                    <span>
                                        <?php if (!empty($msg['phone'])): ?>
                                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($msg['phone']); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="empty-messages" style="margin-top: 2rem;">
                <i class="fas fa-envelope"></i>
                <h3>Add your email to see message history</h3>
                <p>Update your profile with an email address to receive and track replies to your messages.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function previewImage(event) {
            const reader = new FileReader();
            reader.onload = function() {
                const avatarPreview = document.getElementById('avatarPreview');
                avatarPreview.innerHTML = `<img src="${reader.result}" alt="Preview">`;
            }
            reader.readAsDataURL(event.target.files[0]);
        }

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const message = document.querySelector('.message');
            if (message) {
                message.style.transition = 'opacity 0.5s';
                message.style.opacity = '0';
                setTimeout(() => message.remove(), 500);
            }
        }, 5000);
    </script>
</body>
</html>
