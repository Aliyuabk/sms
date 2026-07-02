<?php
// ajax_search_student.php
require_once 'includes/header.php';

header('Content-Type: application/json');

if (!isset($_GET['matric']) || empty($_GET['matric'])) {
    echo json_encode(['success' => false, 'message' => 'Matric number is required']);
    exit();
}

$matric = trim($_GET['matric']);

try {
    $sql = "
        SELECT 
            s.student_id,
            s.matric_number,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.email,
            s.phone,
            s.current_level,
            s.status,
            s.department_id,
            d.department_name,
            p.program_name
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        WHERE s.matric_number = ? AND s.status = 'Active'
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$matric]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        echo json_encode([
            'success' => true,
            'student' => $student
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Student not found or not active'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>