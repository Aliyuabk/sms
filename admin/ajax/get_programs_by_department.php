<?php
require_once '../includes/database.php';

header('Content-Type: application/json');

if (isset($_GET['department_id']) && !empty($_GET['department_id'])) {
    $department_id = (int)$_GET['department_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT program_id, program_name, program_code FROM programs WHERE department_id = ? AND is_active = 1 ORDER BY program_name");
        $stmt->execute([$department_id]);
        $programs = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $programs
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error loading programs: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Department ID is required'
    ]);
}
?>