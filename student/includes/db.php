<?php
/**
 * SMS Database Connection
 * Database: sms
 */

// Database configuration
$host = 'localhost';
$dbname = 'utgoohwm_sms';
$username = 'utgoohwm_kowaguru';      // Change to your MySQL username
$password = 'Jiddaahh@1';          // Change to your MySQL password
$charset = 'utf8mb4';

// Data Source Name
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

// PDO Options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Create PDO connection
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // Log error securely (don't expose details in production)
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// Also create a mysqli connection for legacy code compatibility
$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    error_log("MySQLi connection failed: " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}

// Set charset for mysqli
$conn->set_charset("utf8mb4");
?>