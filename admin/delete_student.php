<?php
session_start();

// Database connection directly
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

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id'])) {
    try {
        $student_id = (int)$_POST['student_id'];
        
        // For now, just delete the student (cascade will handle related records if foreign keys are set up)
        // If you have foreign key constraints with CASCADE DELETE, this will work
        // Otherwise, you need to delete related records first
        
        $stmt = $pdo->prepare("DELETE FROM students WHERE student_id = ?");
        $stmt->execute([$student_id]);
        
        $_SESSION['success_message'] = "Student deleted successfully!";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error deleting student: " . $e->getMessage();
    }
}

header("Location: manage_students.php");
exit();
?>