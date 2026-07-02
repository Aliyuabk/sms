<?php
// ajax_get_courses.php
require_once 'includes/header.php';

header('Content-Type: application/json');

$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

if ($level <= 0 || $department_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

try {
    $sql = "
        SELECT 
            c.course_id,
            c.course_code,
            c.course_title,
            c.credit_units,
            c.level,
            c.semester,
            pre.course_code as prerequisite_code,
            pre.course_title as prerequisite_title
        FROM courses c
        LEFT JOIN courses pre ON c.prerequisite_course_id = pre.course_id
        WHERE c.level = ? AND c.department_id = ? AND c.is_core = 1
        ORDER BY c.semester, c.course_code
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$level, $department_id]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'courses' => $courses
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>