<?php
// Go up one level to access config folder
require_once '../includes/database.php';

header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, log them instead

if (isset($_GET['faculty_id']) && !empty($_GET['faculty_id'])) {
    $faculty_id = (int)$_GET['faculty_id'];
    
    try {
        // Check if PDO connection exists
        if (!isset($pdo) || !$pdo) {
            throw new Exception('Database connection not established');
        }
        
        $stmt = $pdo->prepare("SELECT department_id, department_name, department_code FROM departments WHERE faculty_id = ? ORDER BY department_name");
        $stmt->execute([$faculty_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $departments
        ]);
    } catch (PDOException $e) {
        error_log("Database error in get_departments_by_faculty: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        error_log("General error in get_departments_by_faculty: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Faculty ID is required'
    ]);
}
?>