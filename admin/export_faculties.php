<?php
require_once 'includes/header.php';

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit();
}

$format = $_GET['format'] ?? 'excel';

try {
    $stmt = $pdo->query("
        SELECT f.*, 
               COUNT(d.department_id) as department_count,
               COUNT(DISTINCT s.student_id) as student_count
        FROM faculties f
        LEFT JOIN departments d ON f.faculty_id = d.faculty_id
        LEFT JOIN students s ON d.department_id = s.department_id
        GROUP BY f.faculty_id
        ORDER BY f.faculty_name
    ");
    $faculties = $stmt->fetchAll();
    
    if ($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="faculties_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Faculty Code', 'Faculty Name', 'Dean', 'Email', 'Phone', 'Departments', 'Students', 'Status', 'Created Date']);
        
        foreach ($faculties as $faculty) {
            fputcsv($output, [
                $faculty['faculty_code'],
                $faculty['faculty_name'],
                $faculty['dean_name'],
                $faculty['email'],
                $faculty['phone'],
                $faculty['department_count'],
                $faculty['student_count'],
                $faculty['status'],
                $faculty['created_date']
            ]);
        }
        
        fclose($output);
        exit();
        
    } else {
        // For Excel/PDF - redirect to manage_faculties.php with message
        $_SESSION['export_data'] = $faculties;
        $_SESSION['export_format'] = $format;
        $_SESSION['export_message'] = "Export functionality for $format format will be implemented soon.";
        
        header('Location: manage_faculties.php');
        exit();
    }
    
} catch (PDOException $e) {
    $_SESSION['export_message'] = "Error exporting data: " . $e->getMessage();
    $_SESSION['export_message_type'] = "danger";
    header('Location: manage_faculties.php');
    exit();
}
?>