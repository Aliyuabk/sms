<?php
// export_academic_report.php - Export academic reports to CSV/Excel
ob_start();
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$pdo = new PDO("mysql:host=127.0.0.1;dbname=student_portal_db;charset=utf8mb4", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get filter parameters
$faculty_id = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$session_year = isset($_GET['session_year']) ? $_GET['session_year'] : '';
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'gpa_summary';

// Build filters
$filters = [];
$params = [];

if ($faculty_id > 0) {
    $filters[] = "f.faculty_id = ?";
    $params[] = $faculty_id;
}
if ($department_id > 0) {
    $filters[] = "d.department_id = ?";
    $params[] = $department_id;
}
if ($program_id > 0) {
    $filters[] = "s.program_id = ?";
    $params[] = $program_id;
}
if (!empty($session_year)) {
    $filters[] = "ar.session_year = ?";
    $params[] = $session_year;
}
if ($semester > 0) {
    $filters[] = "ar.semester = ?";
    $params[] = $semester;
}
if ($level > 0) {
    $filters[] = "ar.level = ?";
    $params[] = $level;
}

$where_clause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";

if ($report_type == 'gpa_summary') {
    $sql = "
        SELECT 
            ar.session_year,
            CASE WHEN ar.semester = 1 THEN 'First Semester' ELSE 'Second Semester' END as semester_name,
            ar.level,
            d.department_name,
            p.program_name,
            COUNT(DISTINCT ar.student_id) as student_count,
            ROUND(AVG(ar.gpa), 2) as avg_gpa,
            ROUND(MAX(ar.gpa), 2) as max_gpa,
            ROUND(MIN(ar.gpa), 2) as min_gpa,
            SUM(CASE WHEN ar.gpa >= 4.5 THEN 1 ELSE 0 END) as first_class,
            SUM(CASE WHEN ar.gpa >= 3.5 AND ar.gpa < 4.5 THEN 1 ELSE 0 END) as second_upper,
            SUM(CASE WHEN ar.gpa >= 2.5 AND ar.gpa < 3.5 THEN 1 ELSE 0 END) as second_lower,
            SUM(CASE WHEN ar.gpa >= 1.5 AND ar.gpa < 2.5 THEN 1 ELSE 0 END) as third_class,
            SUM(CASE WHEN ar.gpa < 1.5 THEN 1 ELSE 0 END) as pass
        FROM academic_records ar
        JOIN students s ON ar.student_id = s.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        $where_clause
        GROUP BY ar.session_year, ar.semester, ar.level, d.department_id, p.program_id
        ORDER BY ar.session_year DESC, ar.semester DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gpa_summary_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['Session', 'Semester', 'Level', 'Department', 'Program', 'Students', 'Avg GPA', 'Max GPA', 'Min GPA', 'First Class', 'Second Upper', 'Second Lower', 'Third Class', 'Pass']);
    
    foreach ($data as $row) {
        fputcsv($output, [
            $row['session_year'], $row['semester_name'], $row['level'], $row['department_name'], $row['program_name'],
            $row['student_count'], $row['avg_gpa'], $row['max_gpa'], $row['min_gpa'],
            $row['first_class'], $row['second_upper'], $row['second_lower'], $row['third_class'], $row['pass']
        ]);
    }
} else {
    $sql = "
        SELECT 
            s.matric_number,
            CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name,
            d.department_name,
            p.program_name,
            COUNT(DISTINCT ar.record_id) as semesters_completed,
            SUM(ar.total_units) as total_credits,
            SUM(ar.total_points) as total_points,
            ROUND(SUM(ar.total_points) / NULLIF(SUM(ar.total_units), 0), 2) as cgpa,
            ROUND(MAX(ar.gpa), 2) as best_gpa,
            ROUND(MIN(ar.gpa), 2) as worst_gpa,
            CASE 
                WHEN ROUND(SUM(ar.total_points) / NULLIF(SUM(ar.total_units), 0), 2) >= 4.5 THEN 'First Class'
                WHEN ROUND(SUM(ar.total_points) / NULLIF(SUM(ar.total_units), 0), 2) >= 3.5 THEN 'Second Class Upper'
                WHEN ROUND(SUM(ar.total_points) / NULLIF(SUM(ar.total_units), 0), 2) >= 2.5 THEN 'Second Class Lower'
                WHEN ROUND(SUM(ar.total_points) / NULLIF(SUM(ar.total_units), 0), 2) >= 1.5 THEN 'Third Class'
                ELSE 'Pass'
            END as classification
        FROM academic_records ar
        JOIN students s ON ar.student_id = s.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        $where_clause
        GROUP BY s.student_id
        ORDER BY cgpa DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_performance_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['Matric Number', 'Student Name', 'Department', 'Program', 'Semesters', 'Total Credits', 'Total Points', 'CGPA', 'Best GPA', 'Worst GPA', 'Classification']);
    
    foreach ($data as $row) {
        fputcsv($output, [
            $row['matric_number'], $row['student_name'], $row['department_name'], $row['program_name'],
            $row['semesters_completed'], $row['total_credits'], $row['total_points'], 
            $row['cgpa'], $row['best_gpa'], $row['worst_gpa'], $row['classification']
        ]);
    }
}

fclose($output);
exit();
?>