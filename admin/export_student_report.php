<?php
// export_student_report.php - Export student demographic reports
ob_start();
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$pdo = new PDO("mysql:host=127.0.0.1;dbname=student_portal_db;charset=utf8mb4", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$gender = isset($_GET['gender']) ? $_GET['gender'] : '';
$level = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'list';

$filters = [];
$params = [];

if ($department_id > 0) {
    $filters[] = "s.department_id = ?";
    $params[] = $department_id;
}
if ($program_id > 0) {
    $filters[] = "s.program_id = ?";
    $params[] = $program_id;
}
if (!empty($gender)) {
    $filters[] = "s.gender = ?";
    $params[] = $gender;
}
if ($level > 0) {
    $filters[] = "s.current_level = ?";
    $params[] = $level;
}
if (!empty($status)) {
    $filters[] = "s.status = ?";
    $params[] = $status;
}

$where_clause = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";

if ($report_type == 'list') {
    $sql = "
        SELECT 
            s.matric_number,
            CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name) as student_name,
            s.gender,
            s.current_level,
            s.status,
            s.admission_year,
            s.email,
            s.phone,
            d.department_name,
            d.department_code,
            p.program_name,
            p.program_code,
            s.date_of_birth,
            s.nationality,
            s.state_of_origin
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN programs p ON s.program_id = p.program_id
        $where_clause
        ORDER BY s.matric_number
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_list_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['Matric Number', 'Student Name', 'Gender', 'Level', 'Status', 'Admission Year', 'Email', 'Phone', 'Department', 'Program', 'Date of Birth', 'Nationality', 'State of Origin']);
    
    foreach ($data as $row) {
        fputcsv($output, [
            $row['matric_number'], $row['student_name'], $row['gender'], $row['current_level'], 
            $row['status'], $row['admission_year'], $row['email'], $row['phone'],
            $row['department_name'], $row['program_name'], 
            date('d/m/Y', strtotime($row['date_of_birth'])), $row['nationality'], $row['state_of_origin']
        ]);
    }
} else {
    // Demographics summary
    $sql = "
        SELECT 
            s.gender, 
            s.current_level, 
            s.status, 
            COUNT(*) as count 
        FROM students s
        $where_clause 
        GROUP BY s.gender, s.current_level, s.status 
        ORDER BY s.current_level, s.gender
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_demographics_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['Gender', 'Level', 'Status', 'Number of Students']);
    
    foreach ($data as $row) {
        fputcsv($output, [$row['gender'], $row['current_level'], $row['status'], $row['count']]);
    }
}

fclose($output);
exit();
?>