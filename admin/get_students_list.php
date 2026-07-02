<?php
// get_students_list.php
require_once 'includes/header.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT 
            student_id,
            matric_number,
            first_name,
            last_name,
            email,
            current_level,
            department_name
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        WHERE s.status = 'Active'
        ORDER BY s.last_name, s.first_name
        LIMIT 500
    ");
    
    $students = $stmt->fetchAll();
    echo json_encode($students);
} catch (Exception $e) {
    echo json_encode([]);
}
?>