<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (isset($_GET['matric']) && !empty($_GET['matric'])) {
    $matric = strtoupper(trim($_GET['matric']));
    
    $stmt = $pdo->prepare("
        SELECT s.*, d.department_name, p.program_name
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE s.matric_number = ? AND s.status = 'Active'
    ");
    $stmt->execute([$matric]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        echo json_encode(['success' => true, 'student' => $student]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Student not found or inactive']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Matric number required']);
}
?>