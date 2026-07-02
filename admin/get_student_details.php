<?php
// get_student_details.php
require_once 'includes/header.php';

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

header('Content-Type: application/json');

if ($student_id <= 0) {
    echo json_encode(['error' => 'Invalid student ID']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            d.department_name,
            p.program_name,
            EXISTS (
                SELECT 1 FROM hostel_allocations 
                WHERE student_id = s.student_id AND status = 'Active'
            ) as has_allocation
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE s.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    echo json_encode($student);
} catch (Exception $e) {
    echo json_encode(['error' => 'Error fetching student details']);
}
?>