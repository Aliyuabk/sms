<?php
// Include database connection
require_once 'includes/header.php';

// Set header for JSON response
header('Content-Type: application/json');

// Check if department_id is provided
if (isset($_GET['department_id']) && is_numeric($_GET['department_id'])) {
    $dept_id = (int)$_GET['department_id'];
    
    try {
        // Get programs for the selected department
        $stmt = $pdo->prepare("SELECT program_id, program_name FROM programs WHERE department_id = ? ORDER BY program_name");
        $stmt->execute([$dept_id]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($programs);
    } catch (PDOException $e) {
        // Log error and return empty array
        error_log("AJAX Error getting programs: " . $e->getMessage());
        echo json_encode([]);
    }
} else {
    // Return empty array if no department_id
    echo json_encode([]);
}
?>