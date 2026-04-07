<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login | Yadang's Time</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        
        :root {
            --dark-bg: #233728;
            --container-bg: #3e644a;
            --accent-color: #FAA18F;
            --light-color: #F5E9D9;
            --input-bg: #4c7c5a;
            --text-light: #F5E9D9;
            --text-dark: #1b2a1f;
            --shadow-color: rgba(31, 57, 33, 0.34);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: var(--dark-bg);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        /* ===== LOGO CONTAINER ===== */
        .logo-container {
            text-align: center;
            margin-top: -30px;   
            margin-bottom: 20px;
        }
        
        .logo-image {
            width: 120px;
            background: var(--input-bg);
            padding: 10px;
            border-radius: 50%;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        
        /* ===== MAIN LOGIN CONTAINER ===== */
        .login-container {
            max-width: 500px;
            width: 100%;
            background: var(--container-bg);
            border-radius: 25px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            padding: 3rem;
            position: relative;
            border: 1px solid rgba(139, 115, 85, 0.1);
            margin-top: 5px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2.5rem;
            color: var(--text-light);
        }
        
        .login-header:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 25%;
            width: 50%;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--accent-color), transparent);
        }
        
        .login-title {
            font-size: 2.5rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .login-subtitle {
            color: var(--text-light);
            opacity: 0.9;
            font-size: 1.1rem;
            max-width: 80%;
            margin: 0 auto;
            line-height: 1.6;
        }
        
        /* ===== SECURITY BADGE ===== */
        .security-badge {
            background: #1C2E1A;
            color: var(--text-light);
            padding: 0.8rem 1.5rem;
            border-radius: 30px;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin: 1rem auto;
            font-weight: bold;
            box-shadow: 0 5px 15px rgba(218, 107, 85, 0.3);
        }
        
        /* ===== FORM STYLES ===== */
        .form-group {
            margin-bottom: 1.8rem;
        }
        
        .form-label {
            display: block;
            color: var(--text-light);
            font-size: 1.1rem;
            margin-bottom: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-color);
            font-size: 1.2rem;
        }
        
        .form-input {
            width: 100%;
            padding: 16px 16px 16px 55px;
            background: rgba(245, 233, 217, 0.2);
            border: 2px solid rgba(218, 107, 85, 0.3);
            border-radius: 12px;
            font-size: 1.1rem;
            color: var(--text-light);
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(218, 107, 85, 0.2);
            background: rgba(245, 233, 217, 0.3);
        }
        
        .form-input::placeholder {
            color: rgba(245, 233, 217, 0.6);
        }
        
        .helper-text {
            font-size: 0.9rem;
            color: var(--text-light);
            opacity: 0.8;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* ===== BUTTONS ===== */
        .btn-login {
            width: 100%;
            background: var(--accent-color);
            color: var(--text-light);
            border: none;
            padding: 18px;
            border-radius: 12px;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(218, 107, 85, 0.3);
        }
        
        .btn-login:hover:before {
            left: 100%;
        }
        
        /* ===== FOOTER ===== */
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            color: #F5E9D9;
            opacity: 0.7;
            font-size: 0.9rem;
            font-style: italic;
        }
        
        /* ===== ANIMATIONS ===== */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes securePulse {
            0% { box-shadow: 0 0 0 0 rgba(218, 107, 85, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(218, 107, 85, 0); }
            100% { box-shadow: 0 0 0 0 rgba(218, 107, 85, 0); }
        }
        
        .login-container {
            animation: fadeIn 0.8s ease, securePulse 2s infinite;
        }
        
        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 600px) {
            .login-container {
                padding: 2rem;
                margin-top: -50px;
                max-width: 90%;
            }
            
            .logo-image {
                width: 100px;
            }
            
            .login-title {
                font-size: 2rem;
            }
            
            .form-input {
                padding: 14px 14px 14px 50px;
            }
            
            .security-badge {
                padding: 0.6rem 1.2rem;
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 400px) {
            .login-container {
                padding: 1.5rem;
            }
            
            .logo-image {
                width: 80px;
            }
            
            .login-title {
                font-size: 1.8rem;
            }
            
            .btn-login {
                padding: 14px;
            }
        }
    </style>
</head>

<body>
    <!-- Main Login Container -->
    <div class="login-container">
        <div class="logo-container">
            <img src="images/yadangs_logo.png" alt="Yadang's Time Logo" class="logo-image">
        </div>
        
        <div class="login-header">
            <h1 class="login-title">
                Customer Login
            </h1>
            
            <div class="security-badge">
                Yadang's Time Online Ordering System
            </div>
        </div>
        
        <form id="customerForm">
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-mobile-alt"></i>
                    <span>Phone Number</span>
                </label>
                <div class="input-with-icon">
                    <i class="fas fa-phone"></i>
                    <input type="tel" 
                           id="customerPhone" 
                           name="phone" 
                           class="form-input"
                           placeholder="Example: 012-345-6789 or 012-3456-7890"
                           required
                           inputmode="numeric"
                           autocomplete="tel">
                </div>
                <div class="helper-text">
                    <i class="fas fa-info-circle"></i>
                    Enter 10 or 11 digit Malaysian number
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-smile"></i>
                    <span>Nickname</span>
                </label>
                <div class="input-with-icon">
                    <i class="fas fa-user"></i>
                    <input type="text" 
                           id="customerNickname" 
                           name="nickname" 
                           class="form-input"
                           placeholder="What should we call you?"
                           required
                           maxlength="20"
                           autocomplete="nickname">
                </div>
                <div class="helper-text">
                    <i class="fas fa-info-circle"></i>
                    We'll use this name for your orders
                </div>
            </div>
            
            <button type="submit" class="btn-login">
                <i class="fas fa-mug-hot"></i> Order Drinks Now
            </button>
        </form>
        
        <div class="login-footer">
            Yadang's Time | More brews, less blues
        </div>
    </div>
    
    <script>
    // ===== FORM SUBMISSION =====
    document.getElementById('customerForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        let phoneInput = document.getElementById('customerPhone');
        let phone = phoneInput.value;
        const nickname = document.getElementById('customerNickname').value.trim();
        
        // Remove all non-digits
        const cleanPhone = phone.replace(/\D/g, '');
        
        // Validation for 10-11 digits
        if (cleanPhone.length < 10 || cleanPhone.length > 11) {
            showError('Please enter 10 or 11 digit phone number');
            phoneInput.focus();
            return;
        }
        
        // Check Malaysia prefix (01)
        if (!cleanPhone.startsWith('01')) {
            showError('Malaysian phone numbers should start with 01');
            phoneInput.focus();
            return;
        }
        
        // Nickname validation
        if (nickname.length < 2) {
            showError('Please enter a nickname (minimum 2 characters)');
            document.getElementById('customerNickname').focus();
            return;
        }
        
        // Auto-format the phone number for display
        let formattedPhone = '';
        if (cleanPhone.length === 10) {
            // Format: 012-345-6789
            formattedPhone = cleanPhone.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
        } else if (cleanPhone.length === 11) {
            // Format: 012-3456-7890
            formattedPhone = cleanPhone.replace(/(\d{3})(\d{4})(\d{4})/, '$1-$2-$3');
        }
        
        // Update the input with formatted version
        phoneInput.value = formattedPhone;
        
        // Save to localStorage
        localStorage.setItem('yadangs_customer', JSON.stringify({
            phone: cleanPhone,      // Store clean version (no dashes)
            displayPhone: formattedPhone, // Store formatted version for display
            nickname: nickname,
            loginTime: new Date().toISOString()
        }));
        
        // Show loading state
        const button = document.querySelector('.btn-login');
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
        button.disabled = true;
        
        // Submit via POST
        setTimeout(() => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'process_login.php';
            
            const phoneInput = document.createElement('input');
            phoneInput.type = 'hidden';
            phoneInput.name = 'phone';
            phoneInput.value = cleanPhone; // Send clean version to server
            
            const nicknameInput = document.createElement('input');
            nicknameInput.type = 'hidden';
            nicknameInput.name = 'nickname';
            nicknameInput.value = nickname;
            
            form.appendChild(phoneInput);
            form.appendChild(nicknameInput);
            document.body.appendChild(form);
            form.submit();
        }, 1000);
    });
    
    // ===== PHONE NUMBER FORMATTING (REAL-TIME) =====
    document.getElementById('customerPhone').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        
        // Limit to 11 digits
        if (value.length > 11) {
            value = value.slice(0, 11);
        }
        
        // Format as user types
        if (value.length <= 3) {
            // Just the prefix (012)
            value = value;
        } else if (value.length <= 7) {
            // 012-345 (for 10 digit) or 012-3456 (for 11 digit)
            if (value.length === 7) {
                // Check if this is leading to 10 or 11 digit
                value = value.replace(/(\d{3})(\d{4})/, '$1-$2');
            } else {
                value = value.replace(/(\d{3})(\d{1,3})/, '$1-$2');
            }
        } else {
            // Complete formatting based on length
            if (value.length === 10) {
                value = value.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
            } else if (value.length === 11) {
                value = value.replace(/(\d{3})(\d{4})(\d{4})/, '$1-$2-$3');
            }
        }
        
        e.target.value = value;
    });
    
    // ===== AUTO-FOCUS AND LOAD SAVED DATA =====
    document.addEventListener('DOMContentLoaded', function() {
        // Load saved user data if exists
        const savedUser = localStorage.getItem('yadangs_customer');
        if (savedUser) {
            try {
                const user = JSON.parse(savedUser);
                // Format the saved phone number for display
                let displayPhone = user.displayPhone || formatPhone(user.phone);
                document.getElementById('customerPhone').value = displayPhone;
                document.getElementById('customerNickname').value = user.nickname;
            } catch (e) {
                console.log('Error loading saved user data');
            }
        }
        
        // Auto-focus on phone input
        setTimeout(() => {
            document.getElementById('customerPhone').focus();
        }, 300);
    });
    
    // Helper function to format phone number
    function formatPhone(phone) {
        phone = phone.toString().replace(/\D/g, '');
        
        if (phone.length === 10) {
            return phone.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
        } else if (phone.length === 11) {
            return phone.replace(/(\d{3})(\d{4})(\d{4})/, '$1-$2-$3');
        } else if (phone.length > 3 && phone.length <= 6) {
            return phone.replace(/(\d{3})(\d{1,3})/, '$1-$2');
        } else if (phone.length > 6) {
            return phone.replace(/(\d{3})(\d{3})(\d{1,4})/, '$1-$2-$3');
        }
        return phone;
    }
    
    // Error display function
    function showError(message) {
        // Remove existing error if any
        const existingError = document.querySelector('.error-toast');
        if (existingError) {
            existingError.remove();
        }
        
        // Create error alert
        const alert = document.createElement('div');
        alert.className = 'error-toast';
        alert.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #E74C3C;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            animation: slideInRight 0.3s ease;
        `;
        alert.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        document.body.appendChild(alert);
        
        setTimeout(() => {
            alert.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => alert.remove(), 300);
        }, 3000);
        
        // Add keyframes for animation if not exists
        if (!document.querySelector('#alertStyles')) {
            const style = document.createElement('style');
            style.id = 'alertStyles';
            style.innerHTML = `
                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOutRight {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
    }
    </script>
</body>
</html>
