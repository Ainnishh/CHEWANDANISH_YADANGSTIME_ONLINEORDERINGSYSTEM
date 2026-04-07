<?php
session_start();

// ===== DATABASE CONNECTION =====
$host = 'localhost';
$dbname = 'yadangs_db';
$username = 'root';
$password = '';  

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+08:00'");
    
} catch(PDOException $e) {
    // Log error and show friendly message
    error_log("Database connection failed: " . $e->getMessage());
    die("System is temporarily unavailable. Please try again later.");
}

// ===== FUNCTION TO CLEAN INPUT =====
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// ===== FUNCTION TO FORMAT PHONE NUMBER =====
function formatPhoneNumber($phone) {
    // Remove all non-digits
    $phone = preg_replace('/\D/', '', $phone);
    
    // Format based on length
    if (strlen($phone) == 10) {
        return preg_replace('/(\d{3})(\d{3})(\d{4})/', '$1-$2-$3', $phone);
    } elseif (strlen($phone) == 11) {
        return preg_replace('/(\d{3})(\d{4})(\d{4})/', '$1-$2-$3', $phone);
    }
    return $phone;
}

// ===== GET INPUT DATA =====
$phone = '';
$nickname = '';

// Check from POST (form submission)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone = $_POST['phone'] ?? '';
    $nickname = $_POST['nickname'] ?? '';
} 
// Check from GET (for auto-login from localStorage)
elseif (isset($_GET['phone']) && isset($_GET['nickname'])) {
    $phone = $_GET['phone'];
    $nickname = $_GET['nickname'];
}

// Clean inputs
$phone = cleanInput($phone);
$nickname = cleanInput($nickname);

// ===== VALIDATION =====
$errors = [];

// Remove all non-digits from phone for validation
$clean_phone = preg_replace('/\D/', '', $phone);

if (empty($phone)) {
    $errors[] = "Phone number is required";
} elseif (strlen($clean_phone) < 10 || strlen($clean_phone) > 11) {
    $errors[] = "Phone number must be 10-11 digits";
} elseif (!preg_match('/^01[0-9]/', $clean_phone)) {
    $errors[] = "Phone number must start with 01 (Malaysian number)";
}

if (empty($nickname)) {
    $errors[] = "Nickname is required";
} elseif (strlen($nickname) < 2) {
    $errors[] = "Nickname must be at least 2 characters";
} elseif (strlen($nickname) > 50) {
    $errors[] = "Nickname must be less than 50 characters";
} elseif (!preg_match('/^[a-zA-Z0-9\s]+$/', $nickname)) {
    $errors[] = "Nickname can only contain letters, numbers and spaces";
}

// If there are validation errors
if (!empty($errors)) {
    $error_string = implode(', ', $errors);
    header('Location: login.php?error=' . urlencode($error_string));
    exit();
}

// ===== PROCESS LOGIN =====
try {
    // Check if user exists by phone
    $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
    $stmt->execute([$clean_phone]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        // ===== USER EXISTS - UPDATE EXISTING RECORD =====
        $user_id = $existingUser['id'];
        
        // Update nickname if changed
        if ($existingUser['nickname'] !== $nickname) {
            $updateStmt = $pdo->prepare("UPDATE users SET nickname = ? WHERE id = ?");
            $updateStmt->execute([$nickname, $user_id]);
        }
        
        // Update last login and login count
        try {
            // Check if login_count column exists
            $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('login_count', $columns)) {
                $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW(), login_count = login_count + 1 WHERE id = ?");
            } else {
                $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            }
            $updateStmt->execute([$user_id]);
        } catch (PDOException $e) {
            // Ignore if columns don't exist
            error_log("Login count update failed: " . $e->getMessage());
        }
        
        // Use existing data for session
        $display_nickname = $existingUser['nickname'];
        
    } else {
        // ===== NEW USER - CREATE NEW RECORD =====
        
        // Check table structure
        $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        
        // Build insert query based on existing columns
        $insert_fields = ['phone', 'nickname', 'created_at'];
        $insert_values = [$clean_phone, $nickname, date('Y-m-d H:i:s')];
        $placeholders = ['?', '?', '?'];
        
        // Add optional columns if they exist
        if (in_array('name', $columns)) {
            $insert_fields[] = 'name';
            $insert_values[] = $nickname;
            $placeholders[] = '?';
        }
        
        if (in_array('last_login', $columns)) {
            $insert_fields[] = 'last_login';
            $insert_values[] = date('Y-m-d H:i:s');
            $placeholders[] = '?';
        }
        
        if (in_array('login_count', $columns)) {
            $insert_fields[] = 'login_count';
            $insert_values[] = 1;
            $placeholders[] = '?';
        }
        
        // Build and execute query
        $insert_query = "INSERT INTO users (" . implode(', ', $insert_fields) . ") 
                        VALUES (" . implode(', ', $placeholders) . ")";
        
        $insertStmt = $pdo->prepare($insert_query);
        $insertStmt->execute($insert_values);
        
        $user_id = $pdo->lastInsertId();
        $display_nickname = $nickname;
    }
    
    // ===== SET SESSION VARIABLES =====
    $_SESSION['user_id'] = $user_id;
    $_SESSION['phone'] = $clean_phone;
    $_SESSION['nickname'] = $display_nickname;
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['session_expire'] = time() + 3600; // 1 hour
    $_SESSION['first_login'] = true; // <--- TAMBAH UNTUK POPUP MANUAL
    
    // Format phone for display
    $_SESSION['formatted_phone'] = formatPhoneNumber($clean_phone);
    
    // Set session cookie parameters
    session_regenerate_id(true); // Prevent session fixation
    
    // Optional: Save to localStorage via JavaScript (for auto-login)
    echo '<script>
        localStorage.setItem("yadangs_user", JSON.stringify({
            user_id: ' . $user_id . ',
            phone: "' . $clean_phone . '",
            nickname: "' . $display_nickname . '",
            login_time: "' . date('Y-m-d H:i:s') . '"
        }));
    </script>';
    
    // ===== REDIRECT TO HOME WITH FIRST_LOGIN FLAG =====
    header('Location: home.php?first_login=1');
    exit();
    
} catch (PDOException $e) {
    // Log database error
    error_log("Login process error: " . $e->getMessage());
    
    // Fallback: use session ID
    $_SESSION['user_id'] = session_id();
    $_SESSION['phone'] = $clean_phone;
    $_SESSION['nickname'] = $nickname;
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['session_expire'] = time() + 3600;
    $_SESSION['formatted_phone'] = formatPhoneNumber($clean_phone);
    $_SESSION['offline_mode'] = true;
    $_SESSION['first_login'] = true; // <--- TAMBAH UNTUK POPUP MANUAL
    
    header('Location: home.php?first_login=1&offline=1');
    exit();
}

// ===== FUNCTION TO CHECK SESSION EXPIRY =====
function checkSessionExpiry() {
    if (isset($_SESSION['session_expire']) && time() > $_SESSION['session_expire']) {
        session_unset();
        session_destroy();
        header('Location: login.php?error=session_expired');
        exit();
    }
}

// ===== FUNCTION TO GET USER DATA =====
function getUserData($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}
?>
