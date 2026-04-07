<?php
session_start();
session_unset();
session_destroy();

// Clear cookies
setcookie('yadangs_phone', '', time() - 3600, '/');
setcookie('yadangs_nickname', '', time() - 3600, '/');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logging out...</title>
    <style>
        body {
            background: #1a2c22;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            font-family: sans-serif;
            color: #F5E9D9;
        }
    </style>
</head>
<body>
    <div style="text-align: center;">
        <h2>Logging out...</h2>
        <p>Redirecting to login page...</p>
    </div>
    
    <script>
        // Clear localStorage
        localStorage.removeItem('yadangs_customer');
        localStorage.removeItem('yadangs_cart');
        
        // Redirect after 2 seconds
        setTimeout(() => {
            window.location.href = 'login.php';
        }, 2000);
    </script>
</body>
</html>
