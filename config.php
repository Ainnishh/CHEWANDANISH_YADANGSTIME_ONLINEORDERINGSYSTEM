<?php
$host = 'localhost';
$dbname = 'yadangs_db';
$username = 'root';
$password = '';  // NULL

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET time_zone = '+08:00'");
} catch(PDOException $e) {
    file_put_contents('db_errors.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    $conn = null;
}
?>
