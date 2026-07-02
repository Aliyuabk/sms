<?php
// ajax_handler.php
session_start();
require_once 'config/database.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Log access
error_log("ajax_handler.php accessed with action: " . ($_GET['action'] ?? 'none'));

// Check login
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please login again']);
    exit();
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'search_student':
        searchStudent($pdo);
        break;
        
    case 'get_courses':
        getCourses($pdo);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
}

function searchStudent($pdo) {
    $matric = $_GET['matric'] ?? '';
    
    error_log("Searching for student: " . $matric);
    
    if (empty($matric)) {
        echo json_encode(['success' => false, 'message' => 'Matric number required']);
        return;
    }
    
    try {
        // Test database connection
        $pdo->query("SELECT 1");
        
        $sql = "SELECT 
                    s.*, 
                    d.department_name,
                    p.program_name
                FROM students s 
                LEFT JOIN departments d ON s.department_id = d.department_id 
                LEFT JOIN programs p ON s.program_id = p.program_id
                WHERE s.matric_number = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$matric]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            // Check if student is active
            if ($student['status'] !== 'Active') {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Student found but status is: ' . $student['status']
                ]);
                return;
            }
            
            echo json_encode([
                'success' => true, 
                'student' => $student
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'No student found with matric: ' . $matric
            ]);
        }
    } catch (PDOException $e) {
        error_log("Database error in searchStudent: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Database error occurred'
        ]);
    }
}

function getCourses($pdo) {
    $level = (int)($_GET['level'] ?? 0);
    $dept_id = (int)($_GET['department_id'] ?? 0);
    
    error_log("Getting courses for level: $level, dept: $dept_id");
    
    if ($level <= 0 || $dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }
    
    try {
        $sql = "SELECT 
                    c.course_id,
                    c.course_code,
                    c.course_title,
                    c.credit_units,
                    pre.course_code as prerequisite_code
                FROM courses c
                LEFT JOIN courses pre ON c.prerequisite_course_id = pre.course_id
                WHERE c.level = ? AND c.department_id = ?
                ORDER BY c.course_code";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$level, $dept_id]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'courses' => $courses,
            'count' => count($courses)
        ]);
    } catch (PDOException $e) {
        error_log("Database error in getCourses: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Database error occurred'
        ]);
    }
}
?>