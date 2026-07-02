<?php
require_once 'includes/database.php';

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if (isset($_GET['id']) && isset($_GET['student_id'])) {
    $fee_id = (int)$_GET['id'];
    $student_id = (int)$_GET['student_id'];
    
    try {
        $host = '127.0.0.1';
        $dbname = 'student_portal_db';
        $username = 'root';
        $password = '';
        
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("DELETE FROM student_fees WHERE fee_id = ?");
        $stmt->execute([$fee_id]);
        
        $_SESSION['success_message'] = "Fee invoice deleted successfully!";
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error deleting fee: " . $e->getMessage();
    }
}

header("Location: student_fees.php?id=" . $student_id);
exit();
?>