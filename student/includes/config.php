<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'utgoohwm_kowaguru');
define('DB_PASS', 'Jiddaahh@1');
define('DB_NAME', 'utgoohwm_sms');

// Site configuration
define('SITE_NAME', 'Al-Qalam University Katsina');
define('SITE_URL', 'http://localhost/student-portal');
define('UPLOAD_PATH', $_SERVER['DOCUMENT_ROOT'] . '/student-portal/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
function getDB() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        return $conn;
    } catch (Exception $e) {
        error_log("Database connection error: " . $e->getMessage());
        return null;
    }
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['student_id']);
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
}

// Get student name from session
function getStudentName() {
    return $_SESSION['student_name'] ?? 'Student User';
}

// Get student matric number
function getMatricNumber() {
    return $_SESSION['matric_number'] ?? 'N/A';
}

// Get student initials for avatar
function getStudentInitials() {
    $name = getStudentName();
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    return substr($initials, 0, 2) ?: 'SU';
}
?>