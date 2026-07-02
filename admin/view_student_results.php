<?php
// view_student_results.php - View individual student's complete academic results
ob_start();

require_once 'includes/header.php';

$page_title = "Student Academic Records";

// ==================== DATABASE CONNECTION ====================
if (!isset($pdo)) {
    $host = '127.0.0.1';
    $dbname = 'student_portal_db';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// Get student ID from URL
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($student_id <= 0) {
    $_SESSION['error_message'] = "Invalid student ID.";
    header("Location: manage_students.php");
    exit();
}

// Get student basic information
$student_stmt = $pdo->prepare("
    SELECT 
        s.*,
        d.department_id, d.department_name, d.department_code,
        p.program_id, p.program_name, p.program_code, p.degree_type,
        CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as full_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    WHERE s.student_id = ?
");
$student_stmt->execute([$student_id]);