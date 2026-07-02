<?php
require_once '../includes/header.php';

if (!isset($_GET['faculty_id'])) {
    echo '<div class="alert alert-danger">Faculty ID is required</div>';
    exit();
}

$faculty_id = (int)$_GET['faculty_id'];

try {
    // Get faculty info
    $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$faculty_id]);
    $faculty = $stmt->fetch();
    
    if (!$faculty) {
        echo '<div class="alert alert-danger">Faculty not found</div>';
        exit();
    }
    
    echo '<h6 class="mb-3">Faculty: <strong>' . htmlspecialchars($faculty['faculty_name']) . '</strong></h6>';
    
    // Get departments in this faculty
    $stmt = $pdo->prepare("
        SELECT d.*, 
               (SELECT COUNT(*) FROM programs p WHERE p.department_id = d.department_id) as program_count,
               (SELECT COUNT(*) FROM students s WHERE s.department_id = d.department_id) as student_count
        FROM departments d 
        WHERE d.faculty_id = ?
        ORDER BY d.department_name
    ");
    $stmt->execute([$faculty_id]);
    $departments = $stmt->fetchAll();
    
    if (empty($departments)) {
        echo '<div class="alert alert-info">No departments assigned to this faculty yet.</div>';
    } else {
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-hover">';
        echo '<thead><tr><th>Department</th><th>Code</th><th>Programs</th><th>Students</th><th>Status</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($departments as $dept) {
            $status_class = ($dept['status'] ?? 'Active') == 'Active' ? 'badge bg-success' : 'badge bg-secondary';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($dept['department_name']) . '</td>';
            echo '<td><span class="badge bg-info">' . htmlspecialchars($dept['department_code']) . '</span></td>';
            echo '<td>' . $dept['program_count'] . '</td>';
            echo '<td>' . $dept['student_count'] . '</td>';
            echo '<td><span class="' . $status_class . '">' . ($dept['status'] ?? 'Active') . '</span></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table></div>';
    }
    
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Error loading departments: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>