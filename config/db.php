<?php 
// config/db.php - FIXED: Check if session already started

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ... rest of your db connection code ...

$host = 'localhost';
$username = 'utgoohwm_kowaguru';
$password = 'Jiddaahh@1';
$database = 'utgoohwm_sms';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set timezone
date_default_timezone_set('Africa/Lagos');

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['student_id']);
}

// Function to redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit();
    }
}

// Function to get student data
function getStudentData($conn, $student_id) {
    $sql = "SELECT s.*, d.department_name, p.program_name 
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            WHERE s.student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get current session
function getCurrentSession($conn) {
    $sql = "SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}
?>