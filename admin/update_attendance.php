<?php
// update_attendance.php
session_start();

require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if (isset($_GET['id']) && isset($_GET['attendance'])) {
    $registration_id = (int)$_GET['id'];
    $attendance = (int)$_GET['attendance'];
    
    // Validate attendance percentage
    if ($attendance < 0 || $attendance > 100) {
        $_SESSION['error_message'] = "Invalid attendance percentage. Must be between 0-100.";
        header("Location: course_registrations.php");
        exit();
    }
    
    try {
        $host = '127.0.0.1';
        $dbname = 'student_portal_db';
        $username = 'root';
        $password = '';
        
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // First get student_id for redirect
        $get_sql = "SELECT student_id FROM course_registrations WHERE registration_id = ?";
        $get_stmt = $pdo->prepare($get_sql);
        $get_stmt->execute([$registration_id]);
        $registration = $get_stmt->fetch();
        
        if ($registration) {
            $student_id = $registration['student_id'];
            
            // Update attendance
            $update_sql = "UPDATE course_registrations SET attendance_percentage = ? WHERE registration_id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$attendance, $registration_id]);
            
            $_SESSION['success_message'] = "Attendance updated to $attendance%!";
            header("Location: course_registrations.php?student_id=$student_id");
            exit();
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error updating attendance: " . $e->getMessage();
    }
}

header("Location: course_registrations.php");
exit();
?>