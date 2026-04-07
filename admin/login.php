<?php
session_start();
require_once '../includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($email === 'admin@yadangs.com' && $password === 'yadangstime@9993') {
        
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_name'] = 'Administrator';
        $_SESSION['admin_email'] = $email;
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_first_login'] = true; // For first login popup
        
        try {
            $ip = $_SERVER['REMOTE_ADDR'];
            $stmt = $pdo->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address) VALUES (?, 'login', 'Logged in', ?)");
            $stmt->execute([1, $ip]);
            
            // Check if admin table exists and has last_login column
            try {
                $stmt = $pdo->prepare("UPDATE admin SET last_login = NOW(), last_login_ip = ? WHERE id = 1");
                $stmt->execute([$ip]);
            } catch (PDOException $e) {
                // Table might not exist, ignore
            }
        } catch (PDOException $e) {
            // Log error but continue
            error_log("Admin login log error: " . $e->getMessage());
        }
        
        // Redirect with first_login parameter
        header('Location: dashboard.php?first_login=1');
        exit();
    } else {
        $error = 'Invalid email or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Yadang's Time</title>
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
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a2c22 0%, #2d4638 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .login-container {
            max-width: 450px;
            width: 100%;
            background: var(--container-bg);
            border-radius: 20px;
            padding: 2.5rem;
            border: 2px solid var(--accent-color);
            box-shadow: 0 20px 40px var(--shadow-color);
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo-circle {
            width: 100px;
            height: 100px;
            background: rgba(250, 161, 143, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            border: 3px solid var(--accent-color);
        }
        
        .logo-circle img {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }
        
        h1 {
            color: var(--text-light);
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .subtitle {
            color: var(--accent-color);
            margin-bottom: 2rem;
            font-size: 1rem;
        }
        
        .error-message {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid #e74c3c;
            color: #e74c3c;
            padding: 0.8rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-label i {
            color: var(--accent-color);
        }
        
        .input-wrapper {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
        }
        
        /* Left icon */
        .input-wrapper i:first-child {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-color);
            font-size: 1.2rem;
            z-index: 5;
        }
        
        /* Password toggle button */
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--accent-color);
            cursor: pointer;
            font-size: 1.2rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            z-index: 10;
            border-radius: 50%;
        }
        
        .password-toggle:hover {
            background: rgba(250, 161, 143, 0.15);
            color: var(--light-accent);
        }
        
        /* Input field - with proper padding */
        .form-input {
            width: 100%;
            padding: 1rem 1rem 1rem 45px;
            padding-right: 55px;
            background: rgba(26, 44, 34, 0.7);
            border: 2px solid rgba(250, 161, 143, 0.3);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
            height: 3.5rem;
            line-height: 1.2;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(250, 161, 143, 0.2);
        }
        
        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, var(--accent-color), #ff8e8e);
            color: var(--dark-text);
            border: none;
            padding: 1rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(250, 161, 143, 0.3);
        }
        
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .back-link a {
            color: var(--text-light);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: color 0.3s ease;
        }
        
        .back-link a:hover {
            color: var(--accent-color);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            color: #F5E9D9;
            opacity: 0.7;
            font-size: 0.9rem;
            font-style: italic;
        }
        
        .helper-note {
            color: var(--accent-color);
            font-size: 0.75rem;
            margin-top: 0.3rem;
            display: block;
        }
        
        .helper-note i {
            margin-right: 3px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-section">
            <div class="logo-circle">
                <img src="../images/yadangs_logo.png" alt="Yadang's Time">
            </div>
            <h1>Admin Login</h1>
            <div class="subtitle">Yadang's Time Management System</div>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="loginForm">
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-envelope"></i>
                    Email
                </label>
                <div class="input-wrapper">
                    <i class="fas fa-at"></i>
                    <input type="email" name="email" class="form-input" 
                           placeholder="admin@yadangs.com" value="admin@yadangs.com" required>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-lock"></i>
                    Password
                </label>
                <div class="input-wrapper">
                    <i class="fas fa-key"></i>
                    <input type="password" name="password" id="password" class="form-input" 
                           placeholder="••••••••" value="123" required>
                    <button type="button" class="password-toggle" id="togglePassword" onclick="togglePasswordVisibility()">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
                <small class="helper-note">
                    <i class="fas fa-info-circle"></i> Click eye icon to show/hide password
                </small>
            </div>
            
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i>
                Login to Dashboard
            </button>
        </form>
        
        <div class="login-footer">
            Yadang's Time | More brews, less blues
        </div>
    </div>
    
    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('toggleIcon').className = 'fas fa-eye';
        });
    </script>
</body>
</html>
